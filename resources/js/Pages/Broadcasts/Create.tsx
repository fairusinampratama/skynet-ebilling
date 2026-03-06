import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { ArrowLeft, Send } from "lucide-react";

interface Area {
    id: number;
    name: string;
}

interface Props {
    areas: Area[];
}

export default function Create({ areas }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        target_type: 'all',
        target_area_id: '',
        message_template: 'Halo {name},\n\nTagihan internet Anda sebesar {billing_amount} sudah terbit.\n\nTerima kasih.',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('broadcasts.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center gap-4">
                    <Link href={route('broadcasts.index')}>
                        <Button variant="ghost" size="icon" className="h-8 w-8 rounded-full">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Create Broadcast Campaign
                    </h2>
                </div>
            }
        >
            <Head title="Create Broadcast" />

            <div className="py-8 max-w-3xl mx-auto space-y-6">
                <div className="bg-card shadow-sm border border-border rounded-xl overflow-hidden p-6">
                    <form onSubmit={handleSubmit} className="space-y-6">

                        <div className="space-y-2">
                            <Label htmlFor="name">Campaign Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={e => setData('name', e.target.value)}
                                placeholder="e.g. February Billing Reminder"
                            />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="space-y-2">
                                <Label htmlFor="target_type">Target Audience</Label>
                                <select
                                    id="target_type"
                                    className="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    value={data.target_type}
                                    onChange={e => setData('target_type', e.target.value)}
                                >
                                    <option value="all">All Active Customers</option>
                                    <option value="isolated">Isolated Customers</option>
                                    <option value="area">Specific Area</option>
                                </select>
                                {errors.target_type && <p className="text-sm text-destructive">{errors.target_type}</p>}
                            </div>

                            {data.target_type === 'area' && (
                                <div className="space-y-2">
                                    <Label htmlFor="target_area_id">Select Area</Label>
                                    <select
                                        id="target_area_id"
                                        className="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                        value={data.target_area_id}
                                        onChange={e => setData('target_area_id', e.target.value)}
                                    >
                                        <option value="">-- Choose Area --</option>
                                        {areas.map(area => (
                                            <option key={area.id} value={area.id}>{area.name}</option>
                                        ))}
                                    </select>
                                    {errors.target_area_id && <p className="text-sm text-destructive">{errors.target_area_id}</p>}
                                </div>
                            )}
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="message_template">Message Template</Label>
                                <span className="text-xs text-muted-foreground">
                                    Variables: <code className="bg-muted px-1 py-0.5 rounded">{`{name}`}</code>, <code className="bg-muted px-1 py-0.5 rounded">{`{billing_amount}`}</code>
                                </span>
                            </div>
                            <Textarea
                                id="message_template"
                                rows={8}
                                value={data.message_template}
                                onChange={e => setData('message_template', e.target.value)}
                                className="font-mono text-sm"
                            />
                            {errors.message_template && <p className="text-sm text-destructive">{errors.message_template}</p>}
                        </div>

                        <div className="flex justify-end pt-4 border-t border-border">
                            <Button type="submit" disabled={processing} className="w-full md:w-auto gap-2">
                                <Send className="h-4 w-4" />
                                Start Campaign
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
