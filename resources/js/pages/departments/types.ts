import type { DepartmentGroup } from '@/types/approvals';

export type Member = {
    id: number;
    name: string;
    email: string;
};

export type Department = {
    id: number;
    code: string;
    name: string;
    group: DepartmentGroup;
    parent_id: number | null;
    is_active: boolean;
    pending_count: number;
    members: Member[];
};

export type UserOption = {
    id: number;
    name: string;
    email: string;
};

export type Props = {
    departments: Department[];
    users: UserOption[];
};
