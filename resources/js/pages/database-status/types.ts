export type ConnectionStatus = {
    name: string;
    label: string;
    group: string;
    driver: string | null;
    host: string | null;
    port: string | null;
    database: string | null;
    username: string | null;
    schema: string | null;
    connected: boolean;
    latency_ms: number | null;
    error: string | null;
    error_class: string | null;
};

export type DatabaseStatus = {
    connections: ConnectionStatus[];
    checked_at: string;
};

export type Props = {
    status?: DatabaseStatus;
};
