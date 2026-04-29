import AppLogoIcon from '@/components/app-logo-icon';

export default function AuthLayout({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex min-h-svh w-full items-center justify-center overflow-x-hidden bg-muted px-4 py-10">
            <div className="w-full max-w-md space-y-6">
                <div className="flex flex-col items-center gap-2">
                    <div className="flex aspect-square size-10 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                        <AppLogoIcon className="size-6 fill-current text-white dark:text-black" />
                    </div>
                    <h1 className="text-xl font-semibold">{title}</h1>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>

                {children}
            </div>
        </div>
    );
}
