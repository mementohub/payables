export type UserRow = {
    id: number;
    name: string;
    email: string;
    initials: string;
    departments_count: number;
    created_at: string | null;
};

export type IndexProps = {
    users: UserRow[];
    current_user_id: number;
};

export type EditUser = {
    id: number;
    name: string;
    email: string;
};
