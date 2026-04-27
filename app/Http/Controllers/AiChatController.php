<?php

namespace App\Http\Controllers;

use App\Ai\Agents\FinancialAnalyst;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiChatController extends Controller
{
    public function index(Request $request, ?string $conversation = null): Response
    {
        $user = $request->user();
        abort_unless($user?->isMaster(), 403);

        $conversations = DB::table('agent_conversations')
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'updated_at' => $c->updated_at,
            ]);

        $messages = [];
        $activeConversationId = $conversation;

        if ($conversation) {
            $exists = DB::table('agent_conversations')
                ->where('id', $conversation)
                ->where('user_id', $user->id)
                ->exists();

            abort_unless($exists, 404);

            $messages = DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversation)
                ->whereIn('role', ['user', 'assistant'])
                ->orderBy('created_at')
                ->get(['id', 'role', 'content', 'created_at'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'created_at' => $m->created_at,
                ])
                ->values()
                ->all();
        }

        return Inertia::render('ai-chat/index', [
            'conversations' => $conversations,
            'activeConversationId' => $activeConversationId,
            'messages' => $messages,
        ]);
    }

    public function stream(Request $request, FinancialAnalyst $agent): StreamedResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'size:36'],
        ]);

        $user = $request->user();
        abort_unless($user?->isMaster(), 403);

        $incomingId = $validated['conversation_id'] ?? null;
        $isNewConversation = $incomingId === null;

        if ($incomingId) {
            $owns = DB::table('agent_conversations')
                ->where('id', $incomingId)
                ->where('user_id', $user->id)
                ->exists();
            abort_unless($owns, 404);
        }

        $agent = $incomingId
            ? $agent->continue($incomingId, as: $user)
            : $agent->forUser($user);

        $streamable = $agent->stream($validated['message']);

        $request->session()->save();

        return response()->stream(function () use ($agent, $streamable, $validated, $isNewConversation) {
            ignore_user_abort(true);
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $emit = function (array $payload) {
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            };

            try {
                foreach ($streamable as $event) {
                    if ($event instanceof TextDelta) {
                        $emit(['type' => 'delta', 'text' => $event->delta]);
                    } elseif ($event instanceof ToolCall) {
                        $emit(['type' => 'tool', 'name' => $event->name ?? 'tool']);
                    } elseif ($event instanceof ToolResult) {
                        $emit(['type' => 'tool_done']);
                    }
                }

                // The SDK's RememberConversation middleware calls $agent->continue($newId, ...)
                // when a conversation is created, and persists the messages in its then() callback.
                // Read the id from the agent (authoritative), not from $streamable which is a
                // StreamableAgentResponse the middleware never writes back to.
                $conversationId = $agent->currentConversation();

                if ($isNewConversation && $conversationId) {
                    $title = Str::of($validated['message'])->trim()->limit(80, '…')->value();
                    DB::table('agent_conversations')
                        ->where('id', $conversationId)
                        ->update(['title' => $title ?: 'Întrebare financiară']);
                }

                $emit([
                    'type' => 'done',
                    'conversation_id' => $conversationId,
                ]);
            } catch (\Throwable $e) {
                $emit([
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->isMaster(), 403);

        $owned = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->where('user_id', $user->id)
            ->exists();
        abort_unless($owned, 404);

        DB::table('agent_conversation_messages')->where('conversation_id', $conversation)->delete();
        DB::table('agent_conversations')->where('id', $conversation)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conversație ștearsă.']);

        return redirect()->route('ai-chat.index');
    }
}
