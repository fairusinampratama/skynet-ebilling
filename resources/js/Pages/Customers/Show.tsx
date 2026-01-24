import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import ActivityFeed from '@/Components/ActivityFeed';

interface Package {
    name: string;
    bandwidth_label: string;
}

interface Invoice {
    id: number;
    period: string;
    amount: number;
    status: string;
    due_date: string;
}

interface Transaction {
    id: number;
    amount: number;
    method: string;
    paid_at: string;
    invoice: {
        period: string;
    };
}

interface Customer {
    id: number;
    internal_id: string | null;
    code: string | null;
    name: string;
    address: string;
    phone: string | null;
    nik: string | null;
    pppoe_user: string;
    status: string;
    join_date: string;
    package: Package;
    invoices: Invoice[];
    transactions: Transaction[];
    activities: any[];
}

interface Props {
    customer: Customer;
}

export default function Show({ customer }: Props) {
    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
        }).format(amount);
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('id-ID', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });
    };

    const getStatusBadge = (status: string) => {
        const variants: Record<string, string> = {
            active: 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10',
            suspended: 'text-orange-500 border-orange-500/20 bg-orange-500/10',
            isolated: 'text-red-500 border-red-500/20 bg-red-500/10',
            offboarding: 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10',
            paid: 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10',
            unpaid: 'text-red-500 border-red-500/20 bg-red-500/10',
            void: 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10',
        };
        const className = variants[status] || 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10';
        return (
            <Badge variant="outline" className={`${className} capitalize border px-3 py-1`}>
                {status.toUpperCase()}
            </Badge>
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Customer Details
                    </h2>
                    <Link href={`/customers/${customer.id}/edit`}>
                        <Button variant="outline" className="border-zinc-700 hover:bg-zinc-800 text-foreground">
                            Edit Profile
                        </Button>
                    </Link>
                </div>
            }
        >
            <Head title={customer.name} />

            <div className="py-8">
                <div className="mx-auto max-w-7xl">
                    <Tabs defaultValue="details" className="space-y-6">
                        <TabsList className="bg-zinc-900/50 border border-zinc-800 p-1">
                            <TabsTrigger value="details" className="data-[state=active]:bg-zinc-800 data-[state=active]:text-foreground text-zinc-400">
                                Customer Details
                            </TabsTrigger>
                            <TabsTrigger value="invoices" className="data-[state=active]:bg-zinc-800 data-[state=active]:text-foreground text-zinc-400">
                                Invoices ({customer.invoices.length})
                            </TabsTrigger>
                            <TabsTrigger value="payments" className="data-[state=active]:bg-zinc-800 data-[state=active]:text-foreground text-zinc-400">
                                Payment History ({customer.transactions.length})
                            </TabsTrigger>
                            <TabsTrigger value="activity" className="data-[state=active]:bg-zinc-800 data-[state=active]:text-foreground text-zinc-400">
                                Activity Log
                            </TabsTrigger>
                        </TabsList>

                        {/* Details Tab */}
                        <TabsContent value="details">
                            <Card className="border-zinc-800 bg-card/50 backdrop-blur-sm shadow-none">
                                <CardHeader className="border-b border-zinc-800 bg-zinc-900/20">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-4">
                                            <div className="h-16 w-16 rounded-full bg-zinc-800 flex items-center justify-center text-xl font-bold text-zinc-500">
                                                {customer.name.charAt(0)}
                                            </div>
                                            <div>
                                                <CardTitle className="text-2xl">{customer.name}</CardTitle>
                                                <CardDescription className="font-mono text-zinc-500 mt-1">
                                                    {customer.code || `ID: ${customer.id}`}
                                                </CardDescription>
                                            </div>
                                        </div>
                                        <div>
                                            {getStatusBadge(customer.status)}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="p-8">
                                    <div className="grid gap-8 md:grid-cols-2">
                                        {/* Personal Information */}
                                        <div className="space-y-6">
                                            <div className="flex items-center gap-2 text-lg font-semibold text-foreground">
                                                <h3>Personal Information</h3>
                                            </div>
                                            <Separator className="bg-zinc-800" />
                                            <div className="space-y-4 text-sm">
                                                <div className="grid grid-cols-3 gap-4">
                                                    <span className="text-muted-foreground">Address</span>
                                                    <span className="col-span-2 font-medium text-foreground">{customer.address}</span>
                                                </div>
                                                <div className="grid grid-cols-3 gap-4">
                                                    <span className="text-muted-foreground">Phone</span>
                                                    <span className="col-span-2 font-mono text-foreground">{customer.phone || '-'}</span>
                                                </div>
                                                <div className="grid grid-cols-3 gap-4">
                                                    <span className="text-muted-foreground">NIK</span>
                                                    <span className="col-span-2 font-mono text-foreground">{customer.nik || '-'}</span>
                                                </div>
                                                <div className="grid grid-cols-3 gap-4">
                                                    <span className="text-muted-foreground">Join Date</span>
                                                    <span className="col-span-2 font-medium text-foreground">{formatDate(customer.join_date)}</span>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Service Information */}
                                        <div className="space-y-6">
                                            <div className="flex items-center gap-2 text-lg font-semibold text-foreground">
                                                <h3>Service Configuration</h3>
                                            </div>
                                            <Separator className="bg-zinc-800" />
                                            <div className="space-y-4 text-sm">
                                                <div className="grid grid-cols-3 gap-4 items-center">
                                                    <span className="text-muted-foreground">Package</span>
                                                    <span className="col-span-2 font-medium text-foreground flex items-center gap-2">
                                                        {customer.package.name}
                                                    </span>
                                                </div>
                                                <div className="grid grid-cols-3 gap-4 items-center">
                                                    <span className="text-muted-foreground">Bandwidth</span>
                                                    <div className="col-span-2">
                                                        <Badge variant="outline" className="text-zinc-400 border-zinc-700 bg-zinc-900">
                                                            {customer.package.bandwidth_label}
                                                        </Badge>
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-3 gap-4 items-center">
                                                    <span className="text-muted-foreground">PPPoE User</span>
                                                    <div className="col-span-2">
                                                        <code className="text-xs bg-zinc-900 border border-zinc-800 px-2 py-1 rounded text-emerald-500 font-mono">
                                                            {customer.pppoe_user}
                                                        </code>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Invoices Tab */}
                        <TabsContent value="invoices">
                            <Card className="border-zinc-800 bg-card/50 backdrop-blur-sm shadow-none">
                                <CardHeader>
                                    <CardTitle>Invoice History</CardTitle>
                                    <CardDescription>
                                        Billing records and payment status
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="rounded-md border border-zinc-800 overflow-hidden">
                                        <Table>
                                            <TableHeader className="bg-zinc-900/50">
                                                <TableRow className="border-zinc-800 hover:bg-transparent">
                                                    <TableHead>Invoice ID</TableHead>
                                                    <TableHead>Billing Period</TableHead>
                                                    <TableHead>Amount</TableHead>
                                                    <TableHead>Status</TableHead>
                                                    <TableHead>Due Date</TableHead>
                                                    <TableHead className="text-right">Actions</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {customer.invoices.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                                                            No invoices generated yet
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    customer.invoices.map((invoice) => (
                                                        <TableRow key={invoice.id} className="border-zinc-800 hover:bg-zinc-800/30">
                                                            <TableCell className="font-mono font-medium text-xs">#{invoice.id}</TableCell>
                                                            <TableCell>
                                                                {new Date(invoice.period).toLocaleDateString('id-ID', {
                                                                    year: 'numeric',
                                                                    month: 'long',
                                                                })}
                                                            </TableCell>
                                                            <TableCell className="font-medium font-mono">
                                                                {formatCurrency(invoice.amount)}
                                                            </TableCell>
                                                            <TableCell>
                                                                {getStatusBadge(invoice.status)}
                                                            </TableCell>
                                                            <TableCell className="text-muted-foreground text-xs">
                                                                {formatDate(invoice.due_date)}
                                                            </TableCell>
                                                            <TableCell className="text-right">
                                                                <Link href={`/invoices/${invoice.id}`}>
                                                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                                                        <span className="sr-only">Open</span>
                                                                        <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-4 w-4"><path d="M3 7.5C3 7.22386 3.22386 7 3.5 7H11.5C11.7761 7 12 7.22386 12 7.5C12 7.77614 11.7761 8 11.5 8H3.5C3.22386 8 3 7.77614 3 7.5Z" fill="currentColor" fillRule="evenodd" clipRule="evenodd"></path></svg>
                                                                    </Button>
                                                                </Link>
                                                            </TableCell>
                                                        </TableRow>
                                                    ))
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Payments Tab */}
                        <TabsContent value="payments">
                            <Card className="border-zinc-800 bg-card/50 backdrop-blur-sm shadow-none">
                                <CardHeader>
                                    <CardTitle>Payment History</CardTitle>
                                    <CardDescription>
                                        Confirmed transactions
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="rounded-md border border-zinc-800 overflow-hidden">
                                        <Table>
                                            <TableHeader className="bg-zinc-900/50">
                                                <TableRow className="border-zinc-800 hover:bg-transparent">
                                                    <TableHead>Date Recorded</TableHead>
                                                    <TableHead>For Period</TableHead>
                                                    <TableHead>Method</TableHead>
                                                    <TableHead>Amount Paid</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {customer.transactions.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell colSpan={4} className="text-center text-muted-foreground py-8">
                                                            No payments recorded
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    customer.transactions.map((transaction) => (
                                                        <TableRow key={transaction.id} className="border-zinc-800 hover:bg-zinc-800/30">
                                                            <TableCell className="font-mono text-xs text-muted-foreground">
                                                                {formatDate(transaction.paid_at)}
                                                            </TableCell>
                                                            <TableCell>
                                                                {new Date(transaction.invoice.period).toLocaleDateString('id-ID', {
                                                                    year: 'numeric',
                                                                    month: 'short',
                                                                })}
                                                            </TableCell>
                                                            <TableCell>
                                                                <Badge variant="outline" className="border-zinc-700 text-zinc-400 capitalize">
                                                                    {transaction.method}
                                                                </Badge>
                                                            </TableCell>
                                                            <TableCell className="font-medium font-mono text-emerald-500">
                                                                +{formatCurrency(transaction.amount)}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Activity Tab */}
                        <TabsContent value="activity">
                            <Card className="border-zinc-800 bg-card/50 backdrop-blur-sm shadow-none">
                                <CardHeader>
                                    <CardTitle>Audit Log</CardTitle>
                                    <CardDescription>
                                        History of changes and actions
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ActivityFeed activities={customer.activities} />
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </Tabs>
                </div>
            </div>
        </AuthenticatedLayout >
    );
}
