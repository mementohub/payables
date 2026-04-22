import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Setări aspect" />

            <h1 className="sr-only">Setări aspect</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Setări aspect"
                    description="Modifică setările de aspect ale contului"
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Setări aspect',
            href: editAppearance(),
        },
    ],
};
