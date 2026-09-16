import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <div className="flex items-center justify-center">
            <AppLogoIcon className="size-10 shrink-0 rounded-md" />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    Incasari
                </span>
            </div>
        </div>
    );
}
