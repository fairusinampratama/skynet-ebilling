import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { TextField } from '@/Components/ResourceFields';
import { ResourceFormShell } from '@/Components/ResourceFormShell';
import { ResourcePageHeader } from '@/Components/ResourcePageHeader';
import { requiredId, requiredNumber, requiredString, validateForm } from '@/lib/validation';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { z } from 'zod';

const packageSchema = z.object({
    router_id: requiredId('Router'),
    name: requiredString('Package name'),
    price: requiredNumber('Monthly price', 0),
    mikrotik_profile: requiredString('MikroTik profile'),
});

interface RouterProfile {
    id: number;
    name: string;
    rate_limit?: string | null;
    bandwidth?: string | null;
}

interface Router {
    id: number;
    name: string;
    profiles: RouterProfile[];
}

interface Props {
    routers: Router[];
}

export default function Create({ routers }: Props) {
    const form = useForm({
        router_id: '',
        name: '',
        price: '',
        mikrotik_profile: '',
    });
    const { data, setData, post, processing, errors } = form;
    const selectedRouter = routers.find((router) => String(router.id) === data.router_id);
    const profiles = selectedRouter?.profiles || [];

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!validateForm(packageSchema, data, form)) return;
        post(route('packages.store'));
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Packages', href: route('packages.index') },
                { label: 'Create' },
            ]}
            header={<ResourcePageHeader title="Create Package" backHref={route('packages.index')} />}
        >
            <Head title="Create Package" />

            <ResourceFormShell
                title="Package Details"
                description="Create a new internet package."
                onSubmit={submit}
                submitLabel="Create Package"
                processingLabel="Creating..."
                processing={processing}
                cancelHref={route('packages.index')}
            >
                <div className="space-y-2">
                    <Label htmlFor="router_id">Router</Label>
                    <Select
                        value={data.router_id}
                        onValueChange={(value) => {
                            setData((current) => ({
                                ...current,
                                router_id: value,
                                mikrotik_profile: '',
                            }));
                        }}
                    >
                        <SelectTrigger id="router_id">
                            <SelectValue placeholder="Select router" />
                        </SelectTrigger>
                        <SelectContent>
                            {routers.map((router) => (
                                <SelectItem key={router.id} value={String(router.id)}>
                                    {router.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.router_id && <p className="text-sm text-destructive">{errors.router_id}</p>}
                </div>
                <div className="space-y-2">
                    <Label htmlFor="mikrotik_profile">MikroTik Profile</Label>
                    <Select
                        value={data.mikrotik_profile}
                        onValueChange={(value) => setData('mikrotik_profile', value)}
                        disabled={!selectedRouter || profiles.length === 0}
                    >
                        <SelectTrigger id="mikrotik_profile">
                            <SelectValue placeholder={selectedRouter ? 'Select synced profile' : 'Select router first'} />
                        </SelectTrigger>
                        <SelectContent>
                            {profiles.map((profile) => (
                                <SelectItem key={profile.id} value={profile.name}>
                                    {profile.name}{profile.rate_limit ? ` (${profile.rate_limit})` : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {selectedRouter && profiles.length === 0 && (
                        <p className="text-sm text-muted-foreground">Run a full router sync before creating packages for this router.</p>
                    )}
                    {errors.mikrotik_profile && <p className="text-sm text-destructive">{errors.mikrotik_profile}</p>}
                </div>
                <TextField
                    id="name"
                    label="Package Name"
                    value={data.name}
                    onChange={(value) => setData('name', value)}
                    placeholder="e.g. Paket Premium 20Mbps"
                    required
                    autoFocus
                    maxLength={255}
                    help="This is what customers see on invoices."
                    error={errors.name}
                />
                <TextField
                    id="price"
                    label="Monthly Price (IDR)"
                    type="number"
                    step="1000"
                    min={0}
                    value={data.price}
                    onChange={(value) => setData('price', value)}
                    placeholder="e.g. 150000"
                    required
                    error={errors.price}
                />
            </ResourceFormShell>
        </AuthenticatedLayout>
    );
}
