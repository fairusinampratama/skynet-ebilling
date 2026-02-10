import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { ChevronLeft } from 'lucide-react';

interface Props {
    area: {
        id: number;
        name: string;
        code: string;
    };
}

export default function Edit({ area }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: area.name,
        code: area.code,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('areas.update', area.id));
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Areas', href: route('areas.index') },
                { label: `Edit ${area.name}` },
            ]}
            header={
                <div className="flex items-center gap-4">
                    <Link href={route('areas.index')}>
                        <Button variant="ghost" size="icon" className="rounded-full">
                            <ChevronLeft className="h-5 w-5" />
                        </Button>
                    </Link>
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Edit Area
                    </h2>
                </div>
            }
        >
            <Head title={`Edit ${area.name}`} />

            <div className="py-8 max-w-2xl mx-auto">
                <form onSubmit={submit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>Area Details</CardTitle>
                            <CardDescription>
                                Update information for this area.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="name">Area Name</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. Singosari"
                                    required
                                />
                                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="code">Area Code</Label>
                                <Input
                                    id="code"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    placeholder="e.g. SGS"
                                    required
                                />
                                {errors.code && <p className="text-sm text-destructive">{errors.code}</p>}
                            </div>

                            <div className="flex justify-end pt-4">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
