import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Plus, Trash2, Save } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { toast } from 'sonner';

interface PaymentChannel {
    bank: string;
    account_number: string;
    account_name: string;
}

interface Props {
    settings: any;
    grouped_settings: {
        billing: {
            payment_channels: PaymentChannel[];
            company_name: string;
            company_address: string;
            tripay_api_key?: string;
            tripay_private_key?: string;
            tripay_merchant_code?: string;
            tripay_environment?: string;
        };
    };
}

export default function Index({ grouped_settings }: Props) {
    // We maintain a local form state specifically for the payment channels structure
    // but when submitting, we map it to the generic 'settings' array structure expected by the backend
    const { data, setData, post, processing } = useForm({
        company_name: grouped_settings.billing.company_name,
        company_address: grouped_settings.billing.company_address,
        payment_channels: grouped_settings.billing.payment_channels || [],
        tripay_api_key: grouped_settings.billing.tripay_api_key || '',
        tripay_private_key: grouped_settings.billing.tripay_private_key || '',
        tripay_merchant_code: grouped_settings.billing.tripay_merchant_code || '',
        tripay_environment: grouped_settings.billing.tripay_environment || 'sandbox',
    });

    const addChannel = () => {
        setData('payment_channels', [
            ...data.payment_channels,
            { bank: '', account_number: '', account_name: '' }
        ]);
    };

    const removeChannel = (index: number) => {
        const newChannels = [...data.payment_channels];
        newChannels.splice(index, 1);
        setData('payment_channels', newChannels);
    };

    const updateChannel = (index: number, field: keyof PaymentChannel, value: string) => {
        const newChannels = [...data.payment_channels];
        newChannels[index] = { ...newChannels[index], [field]: value };
        setData('payment_channels', newChannels);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Transform flat data into the backend-expected Settings array format
        const settingsPayload = [
            {
                key: 'payment_channels',
                value: data.payment_channels, // Will be JSON encoded by backend logic or we can ensure it's correct type
                type: 'json',
                group: 'billing'
            },
            {
                key: 'company_name',
                value: data.company_name,
                type: 'text',
                group: 'billing'
            },
            {
                key: 'company_address',
                value: data.company_address,
                type: 'text',
                group: 'billing'
            },
            { key: 'tripay_api_key', value: data.tripay_api_key, type: 'text', group: 'billing' },
            { key: 'tripay_private_key', value: data.tripay_private_key, type: 'text', group: 'billing' },
            { key: 'tripay_merchant_code', value: data.tripay_merchant_code, type: 'text', group: 'billing' },
            { key: 'tripay_environment', value: data.tripay_environment, type: 'text', group: 'billing' }
        ];

        router.post(route('settings.update'), { settings: settingsPayload as any }, {
            onSuccess: () => toast.success('Settings updated successfully'),
            onError: () => toast.error('Failed to update settings'),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-foreground">
                    Settings
                </h2>
            }
        >
            <Head title="Settings" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-6">
                    <form onSubmit={handleSubmit} className="space-y-6">

                        {/* Company Info */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Company Details</CardTitle>
                                <CardDescription>Information used in invoices and receipts.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label>Company Name</Label>
                                    <Input
                                        value={data.company_name}
                                        onChange={e => setData('company_name', e.target.value)}
                                        placeholder="e.g. Skynet Network"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Address</Label>
                                    <Input
                                        value={data.company_address}
                                        onChange={e => setData('company_address', e.target.value)}
                                        placeholder="Full address"
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        {/* Payment Gateway Configuration */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Payment Gateway (Tripay)</CardTitle>
                                <CardDescription>Configure your Tripay credentials here. Sandbox mode recommended for testing.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label>Environment</Label>
                                        <select
                                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                            value={data.tripay_environment}
                                            onChange={e => setData('tripay_environment', e.target.value)}
                                        >
                                            <option value="sandbox">Sandbox (Test)</option>
                                            <option value="production">Production (Live)</option>
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Merchant Code</Label>
                                        <Input
                                            value={data.tripay_merchant_code}
                                            onChange={e => setData('tripay_merchant_code', e.target.value)}
                                            placeholder="T12345"
                                        />
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label>API Key</Label>
                                    <Input
                                        type="password"
                                        value={data.tripay_api_key}
                                        onChange={e => setData('tripay_api_key', e.target.value)}
                                        placeholder="Your API Key"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Private Key</Label>
                                    <Input
                                        type="password"
                                        value={data.tripay_private_key}
                                        onChange={e => setData('tripay_private_key', e.target.value)}
                                        placeholder="Your Private Key"
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        {/* Payment Channels */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <div>
                                    <CardTitle>Payment Channels</CardTitle>
                                    <CardDescription>Bank accounts displayed to customers for manual transfer.</CardDescription>
                                </div>
                                <Button type="button" variant="outline" size="sm" onClick={addChannel}>
                                    <Plus className="w-4 h-4 mr-2" />
                                    Add Account
                                </Button>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {data.payment_channels.length === 0 && (
                                    <p className="text-sm text-muted-foreground italic">No payment channels configured.</p>
                                )}

                                {data.payment_channels.map((channel, index) => (
                                    <div key={index} className="flex gap-4 items-start p-4 border rounded-lg bg-muted/50">
                                        <div className="grid gap-4 flex-1 md:grid-cols-3">
                                            <div className="space-y-2">
                                                <Label>Bank / Provider</Label>
                                                <Input
                                                    placeholder="BCA, Mandiri, Dana"
                                                    value={channel.bank}
                                                    onChange={e => updateChannel(index, 'bank', e.target.value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Account Number</Label>
                                                <Input
                                                    placeholder="1234567890"
                                                    value={channel.account_number}
                                                    onChange={e => updateChannel(index, 'account_number', e.target.value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Account Name</Label>
                                                <Input
                                                    placeholder="PT Skynet"
                                                    value={channel.account_name}
                                                    onChange={e => updateChannel(index, 'account_name', e.target.value)}
                                                />
                                            </div>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="text-destructive hover:text-destructive hover:bg-destructive/10 mt-8"
                                            onClick={() => removeChannel(index)}
                                        >
                                            <Trash2 className="w-4 h-4" />
                                        </Button>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <div className="flex justify-end">
                            <Button type="submit" size="lg" disabled={processing}>
                                <Save className="w-4 h-4 mr-2" />
                                {processing ? 'Saving...' : 'Save Settings'}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
