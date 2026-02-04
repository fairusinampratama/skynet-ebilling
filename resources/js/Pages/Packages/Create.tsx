import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { FormEventHandler, useEffect } from 'react';
import { ProfileSelector } from '@/Components/ProfileSelector';

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
    routers: Router[];
}

export default function Create({ routers }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        router_id: '',
        price: '',
        bandwidth_label: '',
        mikrotik_profile: '',
        rate_limit: '', // Store for reference
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/packages');
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Packages', href: route('packages.index') },
                { label: 'Create' }
            ]}
            header={
                <div className="flex items-center gap-4">
                    <Link href={route('packages.index')}>
                        <Button variant="ghost" size="icon" className="rounded-full">
                            <ChevronLeft className="h-5 w-5" />
                        </Button>
                    </Link>
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Create Package
                    </h2>
                </div>
            }
        >
            <Head title="Create Package" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <Card>
                        <CardHeader>
                            <CardTitle>New Package</CardTitle>
                            <CardDescription>
                                Create a new internet package
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
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
                                        Packages must be assigned to a specific router.
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
                                    <p className="text-sm text-muted-foreground">
                                        Display label for bandwidth (e.g., "10Mbps", "20Mbps")
                                    </p>
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
                                                bandwidth_label: profile.bandwidth || 'Unknown',
                                                rate_limit: profile.rate_limit || '',
                                                name: prev.name || profile.name,
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
                                    <Label htmlFor="name">Business Name (for customers) *</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Paket Premium 20Mbps"
                                        required
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        This is what customers see on invoices
                                    </p>
                                    {errors.name && (
                                        <p className="text-sm text-destructive">{errors.name}</p>
                                    )}
                                </div>

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
                                        {processing ? 'Creating...' : 'Create Package'}
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
