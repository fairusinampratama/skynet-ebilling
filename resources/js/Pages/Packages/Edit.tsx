import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { FormEventHandler } from 'react';
import { ProfileSelector } from '@/Components/ProfileSelector';

interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
    mikrotik_profile?: string;
    rate_limit?: string;
    router_id?: number;
}

interface Router {
    id: number;
    name: string;
    profiles: {
        name: string;
        bandwidth?: string;
        rate_limit?: string;
    }[];
}

interface Props {
    package: Package;
    routers: Router[];
}

export default function Edit({ package: pkg, routers }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: pkg.name,
        router_id: pkg.router_id ? pkg.router_id.toString() : '',
        price: pkg.price.toString(),
        bandwidth_label: pkg.bandwidth_label,
        mikrotik_profile: pkg.mikrotik_profile || '',
        rate_limit: pkg.rate_limit || '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/packages/${pkg.id}`);
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Packages', href: route('packages.index') },
                { label: pkg.name, href: route('packages.show', pkg.id) },
                { label: 'Edit' }
            ]}
            header={
                <div className="flex items-center gap-4">
                    <Link href={route('packages.index')}>
                        <Button variant="ghost" size="icon" className="rounded-full">
                            <ChevronLeft className="h-5 w-5" />
                        </Button>
                    </Link>
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Edit Package
                    </h2>
                </div>
            }
        >
            <Head title="Edit Package" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <Card>
                        <CardHeader>
                            <CardTitle>Edit Package #{pkg.id}</CardTitle>
                            <CardDescription>
                                Update package information
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Alert className="mb-6">
                                <AlertDescription>
                                    <strong>Warning:</strong> Changing the package price will affect all customers
                                    subscribed to this package in future billing cycles.
                                </AlertDescription>
                            </Alert>

                            <form onSubmit={submit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Package Name *</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Paket 10M Promo"
                                        autoFocus
                                        required
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-destructive">{errors.name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="router_id">Assigned Router (Optional)</Label>
                                    <select
                                        id="router_id"
                                        className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                        value={data.router_id}
                                        onChange={(e) => setData('router_id', e.target.value)}
                                        required
                                    >
                                        <option value="" disabled>Select a Router</option>
                                        {routers.map((router) => (
                                            <option key={router.id} value={router.id}>
                                                {router.name}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-sm text-muted-foreground">
                                        Packages are strictly strictly scoped to a router.
                                    </p>
                                    {errors.router_id && (
                                        <p className="text-sm text-destructive">{errors.router_id}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="bandwidth_label">Bandwidth Label *</Label>
                                    <Input
                                        id="bandwidth_label"
                                        value={data.bandwidth_label}
                                        onChange={(e) => setData('bandwidth_label', e.target.value)}
                                        placeholder="e.g., 10Mbps"
                                        required
                                    />
                                    {errors.bandwidth_label && (
                                        <p className="text-sm text-destructive">{errors.bandwidth_label}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="mikrotik_profile">Select Mikrotik Profile *</Label>
                                    <ProfileSelector
                                        profiles={routers.find(r => r.id.toString() === data.router_id)?.profiles || []}
                                        selectedProfile={data.mikrotik_profile}
                                        onSelect={(profile) => {
                                            setData(prev => ({
                                                ...prev,
                                                mikrotik_profile: profile.name,
                                                bandwidth_label: profile.bandwidth || prev.bandwidth_label,
                                                rate_limit: profile.rate_limit || '',
                                            }))
                                        }}
                                        disabled={!data.router_id}
                                    />
                                    {errors.mikrotik_profile && (
                                        <p className="text-sm text-destructive">{errors.mikrotik_profile}</p>
                                    )}
                                </div>

                                {data.rate_limit && (
                                    <div className="rounded-md bg-muted p-3 text-sm">
                                        <p className="font-semibold mb-1">Rate Limit (from router):</p>
                                        <code className="text-xs">{data.rate_limit}</code>
                                    </div>
                                )}

                                <div className="space-y-2">
                                    <Label htmlFor="price">Monthly Price (IDR) *</Label>
                                    <Input
                                        id="price"
                                        type="number"
                                        step="1000"
                                        value={data.price}
                                        onChange={(e) => setData('price', e.target.value)}
                                        placeholder="e.g., 150000"
                                        required
                                    />
                                    {errors.price && (
                                        <p className="text-sm text-destructive">{errors.price}</p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-4">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => window.history.back()}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Saving...' : 'Save Changes'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
