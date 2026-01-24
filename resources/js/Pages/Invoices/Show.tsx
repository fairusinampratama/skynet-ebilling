import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Button } from '@/components/ui/button';

interface Customer {
    id: number;
    name: string;
    code: string | null;
    phone: string | null;
    address: string;
}

interface Transaction {
    id: number;
    amount: number;
    method: string;
    paid_at: string;
    proof_url: string | null;
    admin: {
        name: string;
    } | null;
}

interface Invoice {
    id: number;
    period: string;
    amount: number;
    status: 'unpaid' | 'paid' | 'void';
    due_date: string;
    generated_at: string;
    customer: Customer;
    transactions: Transaction[];
}

interface Props {
    invoice: Invoice;
}

export default function Show({ invoice }: Props) {
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

    const formatDateTime = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('id-ID', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const totalPaid = invoice.transactions.reduce((sum, t) => sum + t.amount, 0);
    const balance = invoice.amount - totalPaid;

    const getStatusBadge = (status: string) => {
        const variants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
            paid: 'default',
            unpaid: 'destructive',
            void: 'secondary',
        };
        return variants[status] || 'outline';
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Invoice #{invoice.id}
                </h2>
            }
        >
            <Head title={`Invoice #${invoice.id}`} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                    {/* Invoice Header */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Invoice #{invoice.id}</CardTitle>
                                    <CardDescription>
                                        Generated on {formatDate(invoice.generated_at)}
                                    </CardDescription>
                                </div>
                                <Badge variant={getStatusBadge(invoice.status)} className="text-lg px-4 py-2">
                                    {invoice.status.toUpperCase()}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-6 md:grid-cols-2">
                                {/* Customer Info */}
                                <div>
                                    <h3 className="font-semibold mb-2">Customer Information</h3>
                                    <div className="space-y-1 text-sm">
                                        <p>
                                            <Link
                                                href={`/customers/${invoice.customer.id}`}
                                                className="font-medium hover:underline"
                                            >
                                                {invoice.customer.name}
                                            </Link>
                                        </p>
                                        {invoice.customer.code && (
                                            <p className="text-muted-foreground">Code: {invoice.customer.code}</p>
                                        )}
                                        {invoice.customer.phone && (
                                            <p className="text-muted-foreground">Phone: {invoice.customer.phone}</p>
                                        )}
                                        <p className="text-muted-foreground">{invoice.customer.address}</p>
                                    </div>
                                </div>

                                {/* Invoice Details */}
                                <div>
                                    <h3 className="font-semibold mb-2">Invoice Details</h3>
                                    <div className="space-y-1 text-sm">
                                        <p>
                                            <span className="text-muted-foreground">Period:</span>{' '}
                                            <span className="font-medium">
                                                {new Date(invoice.period).toLocaleDateString('id-ID', {
                                                    year: 'numeric',
                                                    month: 'long',
                                                })}
                                            </span>
                                        </p>
                                        <p>
                                            <span className="text-muted-foreground">Due Date:</span>{' '}
                                            <span className="font-medium">{formatDate(invoice.due_date)}</span>
                                        </p>
                                        <p>
                                            <span className="text-muted-foreground">Amount:</span>{' '}
                                            <span className="font-bold text-lg">{formatCurrency(invoice.amount)}</span>
                                        </p>
                                        {totalPaid > 0 && (
                                            <>
                                                <p>
                                                    <span className="text-muted-foreground">Paid:</span>{' '}
                                                    <span className="font-medium text-green-600">
                                                        {formatCurrency(totalPaid)}
                                                    </span>
                                                </p>
                                                <p>
                                                    <span className="text-muted-foreground">Balance:</span>{' '}
                                                    <span className="font-medium text-red-600">
                                                        {formatCurrency(balance)}
                                                    </span>
                                                </p>
                                            </>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {invoice.status === 'unpaid' && (
                                <>
                                    <Separator className="my-6" />
                                    <div className="flex justify-end">
                                        <Link href={`/invoices/${invoice.id}/pay`}>
                                            <Button size="lg">Record Payment</Button>
                                        </Link>
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    {/* Payment History */}
                    {invoice.transactions.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Payment History</CardTitle>
                                <CardDescription>
                                    {invoice.transactions.length} payment(s) recorded
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Date & Time</TableHead>
                                            <TableHead>Method</TableHead>
                                            <TableHead>Amount</TableHead>
                                            <TableHead>Recorded By</TableHead>
                                            <TableHead>Proof</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {invoice.transactions.map((transaction) => (
                                            <TableRow key={transaction.id}>
                                                <TableCell className="font-medium">
                                                    {formatDateTime(transaction.paid_at)}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {transaction.method}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {formatCurrency(transaction.amount)}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {transaction.admin?.name || 'System'}
                                                </TableCell>
                                                <TableCell>
                                                    {transaction.proof_url ? (
                                                        <a
                                                            href={transaction.proof_url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-primary hover:underline"
                                                        >
                                                            View
                                                        </a>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
