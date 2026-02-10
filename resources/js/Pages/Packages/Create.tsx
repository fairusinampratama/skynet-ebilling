import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { FormEventHandler, useEffect } from 'react';




export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        price: '',
        mikrotik_profile: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/packages');
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={
                [
                    { label: 'Packages', href: route('packages.index') },
                    { label: 'Create' }
                ]}
            header={
                < div className="flex items-center gap-4" >
                    <Link href={route('packages.index')}>
                        <Button variant="ghost" size="icon" className="rounded-full">
                            <ChevronLeft className="h-5 w-5" />
                        </Button>
                    </Link>
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Create Package
                    </h2>
                </div >
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
                                    <Label htmlFor="mikrotik_profile">Mikrotik Profile Name (Optional)</Label>
                                    <Input
                                        id="mikrotik_profile"
                                        value={data.mikrotik_profile}
                                        onChange={(e) => setData('mikrotik_profile', e.target.value)}
                                        placeholder="e.g., profile-10m (only if used in future)"
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        You can leave this empty for manual packages.
                                    </p>
                                </div>

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
        </AuthenticatedLayout >
    );
}
