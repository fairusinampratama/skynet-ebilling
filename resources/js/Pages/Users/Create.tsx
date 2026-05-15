import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { ChevronLeft, Save } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Area {
    id: number;
    name: string;
    code: string;
}

interface Props {
    areas: Area[];
}

export default function Create({ areas }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: 'admin',
        area_ids: [] as number[],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('users.store'));
    };

    const toggleArea = (areaId: number) => {
        setData('area_ids', data.area_ids.includes(areaId)
            ? data.area_ids.filter((id) => id !== areaId)
            : [...data.area_ids, areaId]);
    };

    return (
        <AuthenticatedLayout breadcrumbs={[{ label: 'Users', href: route('users.index') }, { label: 'Create' }]}>
            <Head title="Create User" />

            <form onSubmit={submit} className="py-8 max-w-3xl space-y-6">
                <Button variant="ghost" asChild>
                    <Link href={route('users.index')}>
                        <ChevronLeft className="mr-2 h-4 w-4" />
                        Back
                    </Link>
                </Button>

                <Card>
                    <CardHeader>
                        <CardTitle>Create User</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label>Name</Label>
                            <Input value={data.name} onChange={(event) => setData('name', event.target.value)} />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Email</Label>
                            <Input type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} />
                            {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Password</Label>
                                <Input type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} />
                                {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Confirm Password</Label>
                                <Input type="password" value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label>Role</Label>
                            <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="admin">Admin</SelectItem>
                                    <SelectItem value="superadmin">Superadmin</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        {data.role === 'admin' && (
                            <div className="space-y-2">
                                <Label>Area Scope</Label>
                                <div className="rounded-md border p-3 grid gap-2 md:grid-cols-2">
                                    {areas.map((area) => (
                                        <label key={area.id} className="flex items-center gap-2 text-sm">
                                            <input type="checkbox" checked={data.area_ids.includes(area.id)} onChange={() => toggleArea(area.id)} />
                                            <span>{area.name}</span>
                                        </label>
                                    ))}
                                </div>
                                <p className="text-xs text-muted-foreground">Leave empty for global admin access.</p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" disabled={processing}>
                        <Save className="mr-2 h-4 w-4" />
                        Create User
                    </Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
