import type { Paginated } from '@/types/pagination';

export type Member = {
    id: number;
    name: string;
    email: string;
};

export type DepartmentType = 'responsabil' | 'ordonator';

export type Department = {
    id: number;
    name: string;
    type: DepartmentType;
    members: Member[];
};

export type UserOption = {
    id: number;
    name: string;
    email: string;
};

export type Filters = {
    search: string | null;
    type: DepartmentType | null;
};

export type Props = {
    departments: Paginated<Department>;
    users: UserOption[];
    filters: Filters;
};
