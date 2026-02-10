import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { FormEventHandler } from 'react';


interface Package {
    id: number;
    name: string;
    price: number;
    mikrotik_profile?: string;
}

interface Props {
    package: Package;
}

export default function Edit({ package: pkg }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: pkg.name,
        price: pkg.price.toString(),
        mikrotik_profile: pkg.mikrotik_profile || '',
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
