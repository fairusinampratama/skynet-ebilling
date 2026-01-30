import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Switch } from '@/Components/ui/switch';
import { ChevronLeft, Save, Server } from 'lucide-react';
import { FormEventHandler } from 'react';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        ip_address: '',
        port: 8728,
        winbox_port: '',
        username: 'admin',
        password: '',
        is_active: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('routers.store'));
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Routers', href: route('routers.index') },
                { label: 'Create' }
            ]}
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href={route('routers.index')}>
                            <Button variant="ghost" size="icon" className="rounded-full">
                                <ChevronLeft className="h-5 w-5" />
                            </Button>
                        </Link>
                        <h2 className="text-xl font-semibold leading-tight text-foreground">
                            Add New Router
                        </h2>
                    </div>
                </div>
            }
        >
            <Head title="Add Router" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <form onSubmit={submit}>
                        <Card className="border-border bg-card">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Server className="h-5 w-5 text-primary" />
                                    Router Configuration
                                </CardTitle>
                                <CardDescription>
                                    Add a new MikroTik router to the network management system.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                {/* Basic Information */}
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium">Basic Information</h3>
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="name">Router Name *</Label>
                                            <Input
                                                id="name"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                placeholder="e.g., Skynet-Tutur"
                                                required
                                                className={errors.name ? 'border-red-500' : ''}
                                            />
                                            {errors.name && (
                                                <p className="text-sm text-red-500">{errors.name}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="ip_address">IP Address *</Label>
                                            <Input
                                                id="ip_address"
                                                value={data.ip_address}
                                                onChange={(e) => setData('ip_address', e.target.value)}
                                                placeholder="103.156.128.231"
                                                required
                                                className={errors.ip_address ? 'border-red-500' : ''}
                                            />
                                            {errors.ip_address && (
                                                <p className="text-sm text-red-500">{errors.ip_address}</p>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Network Configuration */}
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium">Network Configuration</h3>
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="port">API Port</Label>
                                            <Input
                                                id="port"
                                                type="number"
                                                value={data.port}
                                                onChange={(e) => setData('port', parseInt(e.target.value) || 8728)}
                                                placeholder="8728"
                                                className={errors.port ? 'border-red-500' : ''}
                                            />
                                            {errors.port && (
                                                <p className="text-sm text-red-500">{errors.port}</p>
                                            )}
                                            <p className="text-xs text-muted-foreground">Default: 8728</p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="winbox_port">Winbox Port</Label>
                                            <Input
                                                id="winbox_port"
                                                type="number"
                                                value={data.winbox_port}
                                                onChange={(e) => setData('winbox_port', e.target.value)}
                                                placeholder="7291 (optional)"
                                                className={errors.winbox_port ? 'border-red-500' : ''}
                                            />
                                            {errors.winbox_port && (
                                                <p className="text-sm text-red-500">{errors.winbox_port}</p>
                                            )}
                                            <p className="text-xs text-muted-foreground">For remote access</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Authentication */}
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium">Authentication</h3>
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="username">Username *</Label>
                                            <Input
                                                id="username"
                                                value={data.username}
                                                onChange={(e) => setData('username', e.target.value)}
                                                placeholder="admin"
                                                required
                                                className={errors.username ? 'border-red-500' : ''}
                                            />
                                            {errors.username && (
                                                <p className="text-sm text-red-500">{errors.username}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="password">Password *</Label>
                                            <Input
                                                id="password"
                                                type="password"
                                                value={data.password}
                                                onChange={(e) => setData('password', e.target.value)}
                                                placeholder="••••••••"
                                                required
                                                className={errors.password ? 'border-red-500' : ''}
                                            />
                                            {errors.password && (
                                                <p className="text-sm text-red-500">{errors.password}</p>
                                            )}
                                            <p className="text-xs text-muted-foreground">Will be encrypted</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Status */}
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium">Status</h3>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={(checked: boolean) => setData('is_active', checked)}
                                        />
                                        <Label htmlFor="is_active" className="cursor-pointer">
                                            Active (Router is enabled for operations)
                                        </Label>
                                    </div>
                                </div>

                                {/* Actions */}
                                <div className="flex items-center justify-between pt-6 border-t">
                                    <Link href={route('routers.index')}>
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        <Save className="mr-2 h-4 w-4" />
                                        {processing ? 'Saving...' : 'Add Router'}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
