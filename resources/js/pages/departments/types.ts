export type Member = {
    id: number;
    name: string;
    email: string;
};

export type Department = {
    id: number;
    name: string;
    type: 'supervisor' | 'master';
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
