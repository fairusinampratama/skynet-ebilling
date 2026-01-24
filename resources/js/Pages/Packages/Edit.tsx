import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { FormEventHandler } from 'react';

interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
}

interface Props {
    package: Package;
}

export default function Edit({ package: pkg }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: pkg.name,
        price: pkg.price.toString(),
        bandwidth_label: pkg.bandwidth_label,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/packages/${pkg.id}`);
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Edit Package
                </h2>
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
