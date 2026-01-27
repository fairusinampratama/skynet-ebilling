import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ArrowLeft, Edit, Trash2, User, Network, MapPin, Search, ChevronLeft, MoreHorizontal, Eye } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import MapPicker from '@/Components/MapPicker';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";

// Types
interface Transaction {
    id: number;
    amount: number;
    method: string;
    paid_at: string;
}

interface Invoice {
    id: number;
    period: string;
    amount: number;
    status: 'unpaid' | 'paid' | 'void';
    due_date: string;
    transactions: Transaction[];
}

interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
}

interface Customer {
    id: number;
    name: string;
    internal_id: string;
    code: string;
    address: string;
    phone: string;
    nik: string;
    pppoe_user: string;
    status: 'active' | 'suspended' | 'isolated' | 'offboarding';
    geo_lat: string;
    geo_long: string;
    join_date: string;
    package: Package;
    invoices: Invoice[];
}

interface Props {
    customer: Customer;
}

export default function Show({ customer }: Props) {
    const { delete: destroy } = useForm();

    const handleDelete = () => {
        destroy(route('customers.destroy', customer.id));
    };

    const periodDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
    };

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(amount);
    };

    const getStatusBadge = (status: string) => {
        const variants: Record<string, string> = {
            active: 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10',
            params: 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10', // Typo fallback
            paid: 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10',
            suspended: 'text-orange-500 border-orange-500/20 bg-orange-500/10',
            isolated: 'text-red-500 border-red-500/20 bg-red-500/10',
            unpaid: 'text-red-500 border-red-500/20 bg-red-500/10',
            offboarding: 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10',
            void: 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10',
        };
        const className = variants[status] || 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10';

        return (
            <Badge variant="outline" className={`${className} capitalize border`}>
                {status}
            </Badge>
        );
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Customers', href: route('customers.index') },
                { label: customer.name }
            ]}
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href={route('customers.index')}>
                            <Button variant="ghost" size="icon" className="rounded-full">
                                <ChevronLeft className="h-5 w-5" />
                            </Button>
                        </Link>
                        <div>
                            <div className="flex items-center gap-3">
                                <h2 className="text-xl font-semibold leading-tight text-foreground">
                                    {customer.name}
                                </h2>
                                {getStatusBadge(customer.status)}
                            </div>
                            <p className="text-sm text-muted-foreground mt-1 font-mono">
                                ID: {customer.code || customer.internal_id} | PPPoE: {customer.pppoe_user}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('customers.edit', customer.id)}>
                            <Button variant="outline" size="sm">
                                <Edit className="h-4 w-4 mr-2" />
                                Edit Customer
                            </Button>
                        </Link>

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="destructive" size="sm">
                                    <Trash2 className="h-4 w-4 mr-2" />
                                    Delete
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="border-destructive/50">
                                <DialogHeader>
                                    <DialogTitle>Delete Customer Account?</DialogTitle>
                                    <DialogDescription>
                                        This will permanently remove <strong>{customer.name}</strong> from the database.
                                        This action cannot be undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => document.getElementById('close-dialog')?.click()}>Cancel</Button>
                                    <Button variant="destructive" onClick={handleDelete}>Confirm Delete</Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>
            }
        >
            <Head title={`Customer: ${customer.name}`} />

            <div className="py-8">
                <Tabs defaultValue="overview" className="space-y-6">
                    <div className="flex items-center justify-between">
                        <TabsList className="bg-card border border-border">
                            <TabsTrigger value="overview">Overview</TabsTrigger>
                            <TabsTrigger value="invoices">Invoices ({customer.invoices.length})</TabsTrigger>
                            <TabsTrigger value="payments">Payment History</TabsTrigger>
                        </TabsList>
                    </div>

                    {/* OVERVIEW TAB */}
                    <TabsContent value="overview" className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {/* Personal Info */}
                            <Card className="bg-card/50 backdrop-blur border-border">
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <User className="h-4 w-4 text-primary" />
                                        Personal Information
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Full Name</span>
                                        <span className="col-span-2 font-medium">{customer.name}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">NIK</span>
                                        <span className="col-span-2 font-mono">{customer.nik || '-'}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Phone</span>
                                        <span className="col-span-2 font-mono">{customer.phone || '-'}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Address</span>
                                        <span className="col-span-2">{customer.address}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Joined</span>
                                        <span className="col-span-2">{formatDate(customer.join_date)}</span>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Service Info */}
                            <Card className="bg-card/50 backdrop-blur border-border">
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Network className="h-4 w-4 text-blue-500" />
                                        Service Details
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Package</span>
                                        <span className="col-span-2 font-medium">{customer.package.name}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Bandwidth</span>
                                        <span className="col-span-2">
                                            <Badge variant="secondary" className="font-normal">
                                                {customer.package.bandwidth_label}
                                            </Badge>
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Price</span>
                                        <span className="col-span-2 font-medium">{formatCurrency(customer.package.price)} / mo</span>
                                    </div>
                                    <div className="border-t border-border/50 my-2 pt-2"></div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">PPPoE User</span>
                                        <span className="col-span-2 font-mono text-xs bg-muted px-2 py-0.5 rounded w-fit">
                                            {customer.pppoe_user}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Geo Info */}
                            <Card className="bg-card/50 backdrop-blur border-border">
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <MapPin className="h-4 w-4 text-emerald-500" />
                                        Location
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Latitude</span>
                                        <span className="col-span-2 font-mono">{customer.geo_lat || 'N/A'}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Longitude</span>
                                        <span className="col-span-2 font-mono">{customer.geo_long || 'N/A'}</span>
                                    </div>
                                    <div className="mt-4 space-y-4">
                                        <div className="rounded-md overflow-hidden border border-border h-48">
                                            {customer.geo_lat && customer.geo_long ? (
                                                <MapPicker
                                                    initialLat={Number(customer.geo_lat)}
                                                    initialLong={Number(customer.geo_long)}
                                                />
                                            ) : (
                                                <div className="h-full w-full flex items-center justify-center bg-muted text-muted-foreground text-sm">
                                                    No coordinates available
                                                </div>
                                            )}
                                        </div>
                                        <Button variant="outline" size="sm" className="w-full" disabled={!customer.geo_lat} onClick={() => window.open(`https://www.google.com/maps?q=${customer.geo_lat},${customer.geo_long}`, '_blank')}>
                                            Open in Google Maps
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* INVOICES TAB */}
                    <TabsContent value="invoices">
                        <Card className="bg-card/50 backdrop-blur border-border">
                            <CardHeader>
                                <CardTitle>Invoice History</CardTitle>
                                <CardDescription>All billing statements generated for this customer</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow className="border-border hover:bg-transparent">
                                            <TableHead>Invoice #</TableHead>
                                            <TableHead>Billing Period</TableHead>
                                            <TableHead>Amount</TableHead>
                                            <TableHead>Due Date</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {customer.invoices.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                                                    No invoices found.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            customer.invoices.map((inv) => (
                                                <TableRow key={inv.id} className="border-border hover:bg-muted/50">
                                                    <TableCell className="font-mono">#{String(inv.id).padStart(6, '0')}</TableCell>
                                                    <TableCell>{periodDate(inv.period)}</TableCell>
                                                    <TableCell>{formatCurrency(inv.amount)}</TableCell>
                                                    <TableCell className={new Date(inv.due_date) < new Date() && inv.status === 'unpaid' ? 'text-destructive font-bold' : ''}>
                                                        {formatDate(inv.due_date)}
                                                    </TableCell>
                                                    <TableCell>{getStatusBadge(inv.status)}</TableCell>
                                                    <TableCell className="text-right">
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button variant="ghost" className="h-8 w-8 p-0">
                                                                    <span className="sr-only">Open menu</span>
                                                                    <MoreHorizontal className="h-4 w-4" />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem onClick={() => router.visit(route('invoices.show', inv.id))}>
                                                                    <Eye className="mr-2 h-4 w-4" />
                                                                    View Invoice
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* PAYMENTS TAB (Derived from invoice transactions) */}
                    <TabsContent value="payments">
                        <Card className="bg-card/50 backdrop-blur border-border">
                            <CardHeader>
                                <CardTitle>Payment History</CardTitle>
                                <CardDescription>Confimed transactions</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow className="border-border hover:bg-transparent">
                                            <TableHead>Date</TableHead>
                                            <TableHead>Invoice Ref</TableHead>
                                            <TableHead>Method</TableHead>
                                            <TableHead>Amount</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {customer.invoices.flatMap(inv => inv.transactions.map(t => ({ ...t, invoice_id: inv.id }))).length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="h-24 text-center text-muted-foreground">
                                                    No payments recorded yet.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            customer.invoices.flatMap(inv => inv.transactions.map(t => ({ ...t, invoice_id: inv.id })))
                                                .sort((a, b) => new Date(b.paid_at).getTime() - new Date(a.paid_at).getTime())
                                                .map((txn) => (
                                                    <TableRow key={txn.id} className="border-border hover:bg-muted/50">
                                                        <TableCell>{formatDate(txn.paid_at)}</TableCell>
                                                        <TableCell className="font-mono">INV-{String(txn.invoice_id).padStart(6, '0')}</TableCell>
                                                        <TableCell className="capitalize">{txn.method.replace('_', ' ')}</TableCell>
                                                        <TableCell className="font-medium text-emerald-500">
                                                            + {formatCurrency(txn.amount)}
                                                        </TableCell>
                                                    </TableRow>
                                                ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}
