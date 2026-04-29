import type { Components } from 'react-markdown';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

const components: Components = {
    p: ({ ...props }) => (
        <p {...props} className="my-2 leading-relaxed first:mt-0 last:mb-0" />
    ),
    h1: ({ ...props }) => (
        <h1
            {...props}
            className="mt-3 mb-2 text-base font-semibold first:mt-0"
        />
    ),
    h2: ({ ...props }) => (
        <h2 {...props} className="mt-3 mb-2 text-sm font-semibold first:mt-0" />
    ),
    h3: ({ ...props }) => (
        <h3 {...props} className="mt-2 mb-1 text-sm font-semibold first:mt-0" />
    ),
    ul: ({ ...props }) => <ul {...props} className="my-2 list-disc pl-5" />,
    ol: ({ ...props }) => <ol {...props} className="my-2 list-decimal pl-5" />,
    li: ({ ...props }) => <li {...props} className="my-0.5" />,
    strong: ({ ...props }) => <strong {...props} className="font-semibold" />,
    em: ({ ...props }) => <em {...props} className="italic" />,
    a: ({ ...props }) => (
        <a
            {...props}
            target="_blank"
            rel="noopener noreferrer"
            className="underline"
        />
    ),
    code: ({ className, children, ...props }) => {
        const isBlock = /language-/.test(className ?? '');

        if (isBlock) {
            return (
                <code {...props} className={(className ?? '') + ' block'}>
                    {children}
                </code>
            );
        }

        return (
            <code
                {...props}
                className="rounded bg-muted px-1 py-0.5 font-mono text-[0.85em]"
            >
                {children}
            </code>
        );
    },
    pre: ({ ...props }) => (
        <pre
            {...props}
            className="my-2 overflow-x-auto rounded-md bg-muted p-2 font-mono text-xs"
        />
    ),
    blockquote: ({ ...props }) => (
        <blockquote
            {...props}
            className="my-2 border-l-2 border-sidebar-border/70 pl-3 text-muted-foreground dark:border-sidebar-border"
        />
    ),
    hr: ({ ...props }) => (
        <hr
            {...props}
            className="my-3 border-sidebar-border/70 dark:border-sidebar-border"
        />
    ),
    table: ({ ...props }) => (
        <div className="my-2 overflow-x-auto">
            <table
                {...props}
                className="w-full border-collapse border border-sidebar-border/70 text-xs dark:border-sidebar-border"
            />
        </div>
    ),
    th: ({ ...props }) => (
        <th
            {...props}
            className="border border-sidebar-border/70 bg-muted/50 px-2 py-1 text-left font-medium dark:border-sidebar-border"
        />
    ),
    td: ({ ...props }) => (
        <td
            {...props}
            className="border border-sidebar-border/70 px-2 py-1 tabular-nums dark:border-sidebar-border"
        />
    ),
};

export default function MarkdownContent({ content }: { content: string }) {
    return (
        <div className="text-sm break-words">
            <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>
                {content}
            </ReactMarkdown>
        </div>
    );
}
