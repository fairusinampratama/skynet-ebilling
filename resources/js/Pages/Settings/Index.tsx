import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from '@/Components/ui/textarea';
import { Switch } from '@/Components/ui/switch';
import { Plus, Save, Send, Trash2 } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { toast } from 'sonner';
import { validateForm } from '@/lib/validation';
import { z } from 'zod';



interface PaymentChannel {
    bank: string;
    account_number: string;
    account_name: string;
}

interface Props {
    settings: any;
    grouped_settings: {
        billing: {
            company_name: string;
            company_address: string;
            payment_channels: PaymentChannel[];
        };
        whatsapp: {
            enabled: boolean;
            base_url: string;
            device_id: string;
            api_key_configured: boolean;
            test_number: string;
            invoice_reminders_enabled: boolean;
            isolation_notifications_enabled: boolean;
            templates: {
                h_7: string;
                h_5: string;
                h_3: string;
                h_day: string;
                isolation: string;
            };
        };
    };
}

const settingsSchema = z.object({
    company_name: z.string().max(255, 'Company name may not be greater than 255 characters.'),
    company_address: z.string(),
    payment_channels: z.array(z.object({
        bank: z.string().max(100, 'Bank name may not be greater than 100 characters.'),
        account_number: z.string().max(100, 'Account number may not be greater than 100 characters.'),
        account_name: z.string().max(255, 'Account holder may not be greater than 255 characters.'),
    })),
    whatsapp_enabled: z.boolean(),
    whatsapp_base_url: z.string().url('WhatsApp base URL must be a valid URL.'),
    whatsapp_device_id: z.string().max(255, 'Device ID may not be greater than 255 characters.'),
    whatsapp_api_key: z.string().max(2000, 'API key is too long.').optional(),
    whatsapp_test_number: z.string().max(32, 'Test number may not be greater than 32 characters.'),
    whatsapp_invoice_reminders_enabled: z.boolean(),
    whatsapp_isolation_notifications_enabled: z.boolean(),
    whatsapp_template_h_7: z.string().max(5000, 'Template may not be greater than 5000 characters.'),
    whatsapp_template_h_5: z.string().max(5000, 'Template may not be greater than 5000 characters.'),
    whatsapp_template_h_3: z.string().max(5000, 'Template may not be greater than 5000 characters.'),
    whatsapp_template_h_day: z.string().max(5000, 'Template may not be greater than 5000 characters.'),
    whatsapp_template_isolation: z.string().max(5000, 'Template may not be greater than 5000 characters.'),
});

const testSchema = z.object({
    phone: z.string().min(8, 'Phone number is required.').max(32, 'Phone number may not be greater than 32 characters.'),
    message: z.string().max(1000, 'Message may not be greater than 1000 characters.'),
});

export default function Index({ grouped_settings }: Props) {
    const defaultPaymentChannels = [
        { bank: 'BCA', account_number: '1234567890', account_name: 'Skynet Lintas Nusantara' },
        { bank: 'Mandiri', account_number: '0987654321', account_name: 'Skynet Lintas Nusantara' },
    ];
    const paymentChannels = grouped_settings.billing.payment_channels?.length
        ? grouped_settings.billing.payment_channels
        : defaultPaymentChannels;

    const form = useForm({
        company_name: grouped_settings.billing.company_name,
        company_address: grouped_settings.billing.company_address,
        payment_channels: paymentChannels,
        whatsapp_enabled: grouped_settings.whatsapp.enabled,
        whatsapp_base_url: grouped_settings.whatsapp.base_url || 'https://api.whatspie.com',
        whatsapp_device_id: grouped_settings.whatsapp.device_id || '',
        whatsapp_api_key: '',
        whatsapp_test_number: grouped_settings.whatsapp.test_number || '',
        whatsapp_invoice_reminders_enabled: grouped_settings.whatsapp.invoice_reminders_enabled,
        whatsapp_isolation_notifications_enabled: grouped_settings.whatsapp.isolation_notifications_enabled,
        whatsapp_template_h_7: grouped_settings.whatsapp.templates.h_7 || '',
        whatsapp_template_h_5: grouped_settings.whatsapp.templates.h_5 || '',
        whatsapp_template_h_3: grouped_settings.whatsapp.templates.h_3 || '',
        whatsapp_template_h_day: grouped_settings.whatsapp.templates.h_day || '',
        whatsapp_template_isolation: grouped_settings.whatsapp.templates.isolation || '',
    });
    const { data, setData, processing, errors } = form;

    const testForm = useForm({
        phone: grouped_settings.whatsapp.test_number || '',
        message: 'Test WhatsApp dari SkyNet E-Billing.',
    });

    const updatePaymentChannel = (index: number, field: keyof PaymentChannel, value: string) => {
        const nextChannels = data.payment_channels.map((channel, channelIndex) => (
            channelIndex === index ? { ...channel, [field]: value } : channel
        ));

        setData('payment_channels', nextChannels);
    };

    const addPaymentChannel = () => {
        setData('payment_channels', [
            ...data.payment_channels,
            { bank: '', account_number: '', account_name: '' },
        ]);
    };

    const removePaymentChannel = (index: number) => {
        setData('payment_channels', data.payment_channels.filter((_, channelIndex) => channelIndex !== index));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!validateForm(settingsSchema, data, form)) return;

        // Transform flat data into the backend-expected Settings array format
        const settingsPayload = [
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
            {
                key: 'payment_channels',
                value: data.payment_channels,
                type: 'json',
                group: 'billing'
            },
            {
                key: 'whatsapp_enabled',
                value: data.whatsapp_enabled,
                type: 'boolean',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_base_url',
                value: data.whatsapp_base_url,
                type: 'text',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_device_id',
                value: data.whatsapp_device_id,
                type: 'text',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_api_key',
                value: data.whatsapp_api_key,
                type: 'text',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_test_number',
                value: data.whatsapp_test_number,
                type: 'text',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_invoice_reminders_enabled',
                value: data.whatsapp_invoice_reminders_enabled,
                type: 'boolean',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_isolation_notifications_enabled',
                value: data.whatsapp_isolation_notifications_enabled,
                type: 'boolean',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_template_h_7',
                value: data.whatsapp_template_h_7,
                type: 'text',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_template_h_5',
                value: data.whatsapp_template_h_5,
                type: 'text',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_template_h_3',
                value: data.whatsapp_template_h_3,
                type: 'text',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_template_h_day',
                value: data.whatsapp_template_h_day,
                type: 'text',
                group: 'whatsapp'
            },
            {
                key: 'whatsapp_template_isolation',
                value: data.whatsapp_template_isolation,
                type: 'text',
                group: 'whatsapp'
            },
        ];

        router.post(route('settings.update'), { settings: settingsPayload as any }, {
            onError: (serverErrors) => {
                form.setError(serverErrors as Record<string, string>);
                toast.error('Failed to update settings');
            },
        });
    };

    const handleTest = (e: React.FormEvent) => {
        e.preventDefault();
        if (!validateForm(testSchema, testForm.data, testForm)) return;

        router.post(route('settings.whatsapp.test'), testForm.data, {
            preserveScroll: true,
            onError: (serverErrors) => {
                testForm.setError(serverErrors as Record<string, string>);
                toast.error('Failed to send WhatsApp test');
            },
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
                                        placeholder="e.g. PT. SKYNET LINTAS NUSANTARA"
                                        maxLength={255}
                                    />
                                    {errors.company_name && <p className="text-sm text-destructive">{errors.company_name}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Address</Label>
                                    <Input
                                        value={data.company_address}
                                        onChange={e => setData('company_address', e.target.value)}
                                        placeholder="Full address"
                                    />
                                    {errors.company_address && <p className="text-sm text-destructive">{errors.company_address}</p>}
                                </div>

                                <div className="space-y-3 border-t pt-4">
                                    <div className="flex items-center justify-between gap-4">
                                        <div>
                                            <Label>Payment Accounts</Label>
                                            <p className="text-sm text-muted-foreground">Shown on invoice PDFs and payment pages.</p>
                                        </div>
                                        <Button type="button" variant="outline" size="sm" onClick={addPaymentChannel}>
                                            <Plus className="mr-2 h-4 w-4" />
                                            Add Account
                                        </Button>
                                    </div>

                                    <div className="space-y-3">
                                        {data.payment_channels.map((channel, index) => (
                                            <div key={index} className="rounded-md border border-border p-4">
                                                <div className="grid gap-3 md:grid-cols-[1fr_1.3fr_1.2fr_auto]">
                                                    <div className="space-y-2">
                                                        <Label>Bank</Label>
                                                        <Input
                                                            value={channel.bank}
                                                            onChange={e => updatePaymentChannel(index, 'bank', e.target.value)}
                                                            placeholder="BCA"
                                                            maxLength={100}
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>Account Holder</Label>
                                                        <Input
                                                            value={channel.account_name}
                                                            onChange={e => updatePaymentChannel(index, 'account_name', e.target.value)}
                                                            placeholder="Skynet Lintas Nusantara"
                                                            maxLength={255}
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>Account Number</Label>
                                                        <Input
                                                            value={channel.account_number}
                                                            onChange={e => updatePaymentChannel(index, 'account_number', e.target.value)}
                                                            placeholder="1234567890"
                                                            maxLength={100}
                                                        />
                                                    </div>
                                                    <div className="flex items-end">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="icon"
                                                            onClick={() => removePaymentChannel(index)}
                                                            aria-label="Remove payment account"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    {errors.payment_channels && <p className="text-sm text-destructive">{errors.payment_channels}</p>}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>WhatsApp Gateway</CardTitle>
                                <CardDescription>Whatspie connection used by broadcasts, invoice reminders, and isolation notices.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="flex items-center justify-between gap-4 rounded-md border border-border p-4">
                                    <div>
                                        <Label>Gateway Enabled</Label>
                                        <p className="text-sm text-muted-foreground">Allow the system to send WhatsApp messages.</p>
                                    </div>
                                    <Switch
                                        checked={data.whatsapp_enabled}
                                        onCheckedChange={(checked) => setData('whatsapp_enabled', checked)}
                                    />
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Base URL</Label>
                                        <Input
                                            value={data.whatsapp_base_url}
                                            onChange={e => setData('whatsapp_base_url', e.target.value)}
                                            placeholder="https://api.whatspie.com"
                                        />
                                        {errors.whatsapp_base_url && <p className="text-sm text-destructive">{errors.whatsapp_base_url}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Device ID</Label>
                                        <Input
                                            value={data.whatsapp_device_id}
                                            onChange={e => setData('whatsapp_device_id', e.target.value)}
                                            placeholder="Whatspie device ID"
                                        />
                                        {errors.whatsapp_device_id && <p className="text-sm text-destructive">{errors.whatsapp_device_id}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>API Key</Label>
                                        <Input
                                            type="password"
                                            value={data.whatsapp_api_key}
                                            onChange={e => setData('whatsapp_api_key', e.target.value)}
                                            placeholder={grouped_settings.whatsapp.api_key_configured ? 'Configured - leave blank to keep current key' : 'Paste Whatspie API key'}
                                            autoComplete="new-password"
                                        />
                                        {errors.whatsapp_api_key && <p className="text-sm text-destructive">{errors.whatsapp_api_key}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Test Number</Label>
                                        <Input
                                            value={data.whatsapp_test_number}
                                            onChange={e => {
                                                setData('whatsapp_test_number', e.target.value);
                                                testForm.setData('phone', e.target.value);
                                            }}
                                            placeholder="08xxxxxxxxxx"
                                        />
                                        {errors.whatsapp_test_number && <p className="text-sm text-destructive">{errors.whatsapp_test_number}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="flex items-center justify-between gap-4 rounded-md border border-border p-4">
                                        <div>
                                            <Label>Invoice Reminders</Label>
                                            <p className="text-sm text-muted-foreground">Enable H-7, H-5, H-3, and due-day messages.</p>
                                        </div>
                                        <Switch
                                            checked={data.whatsapp_invoice_reminders_enabled}
                                            onCheckedChange={(checked) => setData('whatsapp_invoice_reminders_enabled', checked)}
                                        />
                                    </div>
                                    <div className="flex items-center justify-between gap-4 rounded-md border border-border p-4">
                                        <div>
                                            <Label>Isolation Notices</Label>
                                            <p className="text-sm text-muted-foreground">Send a notice when overdue isolation is triggered.</p>
                                        </div>
                                        <Switch
                                            checked={data.whatsapp_isolation_notifications_enabled}
                                            onCheckedChange={(checked) => setData('whatsapp_isolation_notifications_enabled', checked)}
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>WhatsApp Message Templates</CardTitle>
                                <CardDescription>Edit the WhatsApp text sent for reminders and isolation notices. Available variables: {'{name}'}, {'{period}'}, {'{amount}'}, {'{due_date}'}.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label>Invoice Reminder H-7</Label>
                                    <p className="text-sm text-muted-foreground">Sent 7 days before the invoice due date.</p>
                                    <Textarea
                                        value={data.whatsapp_template_h_7}
                                        onChange={e => setData('whatsapp_template_h_7', e.target.value)}
                                        rows={5}
                                    />
                                    {errors.whatsapp_template_h_7 && <p className="text-sm text-destructive">{errors.whatsapp_template_h_7}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Invoice Reminder H-5</Label>
                                    <p className="text-sm text-muted-foreground">Sent 5 days before the invoice due date.</p>
                                    <Textarea
                                        value={data.whatsapp_template_h_5}
                                        onChange={e => setData('whatsapp_template_h_5', e.target.value)}
                                        rows={5}
                                    />
                                    {errors.whatsapp_template_h_5 && <p className="text-sm text-destructive">{errors.whatsapp_template_h_5}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Invoice Reminder H-3</Label>
                                    <p className="text-sm text-muted-foreground">Sent 3 days before the invoice due date.</p>
                                    <Textarea
                                        value={data.whatsapp_template_h_3}
                                        onChange={e => setData('whatsapp_template_h_3', e.target.value)}
                                        rows={5}
                                    />
                                    {errors.whatsapp_template_h_3 && <p className="text-sm text-destructive">{errors.whatsapp_template_h_3}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Invoice Due Today</Label>
                                    <p className="text-sm text-muted-foreground">Sent on the invoice due date.</p>
                                    <Textarea
                                        value={data.whatsapp_template_h_day}
                                        onChange={e => setData('whatsapp_template_h_day', e.target.value)}
                                        rows={5}
                                    />
                                    {errors.whatsapp_template_h_day && <p className="text-sm text-destructive">{errors.whatsapp_template_h_day}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Isolation Notice</Label>
                                    <p className="text-sm text-muted-foreground">Sent when overdue isolation is triggered.</p>
                                    <Textarea
                                        value={data.whatsapp_template_isolation}
                                        onChange={e => setData('whatsapp_template_isolation', e.target.value)}
                                        rows={5}
                                    />
                                    {errors.whatsapp_template_isolation && <p className="text-sm text-destructive">{errors.whatsapp_template_isolation}</p>}
                                </div>
                            </CardContent>
                        </Card>

                        <div className="flex justify-end">
                            <Button type="submit" size="lg" disabled={processing}>
                                <Save className="w-4 h-4 mr-2" />
                                {processing ? 'Saving...' : 'Save Settings'}
                            </Button>
                        </div>
                    </form>

                    <form onSubmit={handleTest}>
                        <Card>
                            <CardHeader>
                                <CardTitle>Send Test Message</CardTitle>
                                <CardDescription>Uses the saved WhatsApp gateway settings.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 md:grid-cols-[240px_1fr]">
                                    <div className="space-y-2">
                                        <Label>Phone Number</Label>
                                        <Input
                                            value={testForm.data.phone}
                                            onChange={e => testForm.setData('phone', e.target.value)}
                                            placeholder="08xxxxxxxxxx"
                                        />
                                        {testForm.errors.phone && <p className="text-sm text-destructive">{testForm.errors.phone}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Message</Label>
                                        <Textarea
                                            value={testForm.data.message}
                                            onChange={e => testForm.setData('message', e.target.value)}
                                            rows={3}
                                        />
                                        {testForm.errors.message && <p className="text-sm text-destructive">{testForm.errors.message}</p>}
                                    </div>
                                </div>
                                <div className="flex justify-end">
                                    <Button type="submit" variant="outline" disabled={testForm.processing}>
                                        <Send className="mr-2 h-4 w-4" />
                                        {testForm.processing ? 'Sending...' : 'Send Test'}
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
