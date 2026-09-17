import { Component } from 'react';
import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';

/**
 * The report is drawn from a snapshot written by whichever version of the
 * builder last ran, which may be older than the page reading it. When the two
 * disagree about the shape of the payload, the page has to say so and leave
 * the Recalculează button reachable — a blank screen takes away the one
 * action that fixes it.
 */
export default class StoredReportBoundary extends Component<
    { children: ReactNode; builtAt?: string },
    { message: string | null }
> {
    state: { message: string | null } = { message: null };

    static getDerivedStateFromError(error: unknown) {
        return {
            message: error instanceof Error ? error.message : String(error),
        };
    }

    componentDidUpdate(previous: { builtAt?: string }) {
        // A fresh snapshot deserves a fresh attempt.
        if (previous.builtAt !== this.props.builtAt && this.state.message) {
            this.setState({ message: null });
        }
    }

    render() {
        if (this.state.message === null) {
            return this.props.children;
        }

        return (
            <Card>
                <CardContent className="space-y-2 py-10 text-center text-sm">
                    <p className="font-medium">
                        Raportul salvat nu poate fi afișat de versiunea curentă
                        a paginii.
                    </p>
                    <p className="text-muted-foreground">
                        A fost construit înainte de ultima schimbare a
                        raportului. Apasă „Recalculează” ca să fie construit din
                        nou; restul paginii funcționează între timp.
                    </p>
                    <p className="font-mono text-xs text-muted-foreground">
                        {this.state.message}
                    </p>
                </CardContent>
            </Card>
        );
    }
}
