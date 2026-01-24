import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useState } from 'react';
import { router } from '@inertiajs/react';

interface Customer {
    id: number;
    name: string;
    code: string | null;
}

interface Invoice {
    id: number;
    period: string;
    amount: number;
    status: 'unpaid' | 'paid' | 'void';
    due_date: string;
    customer: Customer;
}

interface Props {
    invoices: {
        data: Invoice[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        status?: string;
    };
}

export default function Index({ invoices, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const handleFilter = () => {
        router.get('/invoices', {
            search: search || undefined,
            status: status !== 'all' ? status : undefined,
        }, {
            preserveState: true,
            replace: true,
        });
    };

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
            month: 'short',
            day: 'numeric',
        });
    };

    const getStatusBadge = (status: string) => {
        const variants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
            paid: 'default',
            unpaid: 'destructive',
            void: 'secondary',
        };
        return variants[status] || 'outline';
    };

    const isOverdue = (invoice: Invoice) => {
        return invoice.status === 'unpaid' && new Date(invoice.due_date) < new Date();
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Invoices
                </h2>
            }
        >
            <Head title="Invoices" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <Card>
                        <CardHeader>
                            <CardTitle>Invoice Management</CardTitle>
                            <CardDescription>
                                Showing {invoices.data.length} of {invoices.total} invoices
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {/* Search and Filters */}
                            <div className="mb-6 flex flex-col gap-4 sm:flex-row">
                                <div className="flex-1">
                                    <Input
                                        placeholder="Search by customer name or code..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                    />
                                </div>
                                <Select value={status} onValueChange={setStatus}>
                                    <SelectTrigger className="w-full sm:w-[180px]">
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Statuses</SelectItem>
                                        <SelectItem value="paid">Paid</SelectItem>
                                        <SelectItem value="unpaid">Unpaid</SelectItem>
                                        <SelectItem value="void">Void</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Button onClick={handleFilter}>Filter</Button>
                            </div>

                            {/* Table */}
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>ID</TableHead>
                                        <TableHead>Customer</TableHead>
                                        <TableHead>Period</TableHead>
                                        <TableHead>Amount</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Due Date</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center text-muted-foreground">
                                                No invoices found
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        invoices.data.map((invoice) => (
                                            <TableRow key={invoice.id}>
                                                <TableCell className="font-medium">
                                                    #{invoice.id}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-col">
                                                        <Link
                                                            href={`/customers/${invoice.customer.id}`}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {invoice.customer.name}
                                                        </Link>
                                                        {invoice.customer.code && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {invoice.customer.code}
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {new Date(invoice.period).toLocaleDateString('id-ID', {
                                                        year: 'numeric',
                                                        month: 'long',
                                                    })}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {formatCurrency(invoice.amount)}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={getStatusBadge(invoice.status)}>
                                                        {invoice.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <span className={isOverdue(invoice) ? 'text-red-600 font-medium' : ''}>
                                                        {formatDate(invoice.due_date)}
                                                        {isOverdue(invoice) && ' (Overdue)'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Link href={`/invoices/${invoice.id}`}>
                                                        <Button variant="ghost" size="sm">
                                                            View
                                                        </Button>
                                                    </Link>
                                                    {invoice.status === 'unpaid' && (
                                                        <Link href={`/invoices/${invoice.id}/pay`}>
                                                            <Button variant="default" size="sm" className="ml-2">
                                                                Pay
                                                            </Button>
                                                        </Link>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>

                            {/* Pagination */}
                            {invoices.last_page > 1 && (
                                <div className="mt-4 flex items-center justify-between">
                                    <div className="text-sm text-muted-foreground">
                                        Page {invoices.current_page} of {invoices.last_page}
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={invoices.current_page === 1}
                                            onClick={() => router.get(`/invoices?page=${invoices.current_page - 1}`)}
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={invoices.current_page === invoices.last_page}
                                            onClick={() => router.get(`/invoices?page=${invoices.current_page + 1}`)}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
