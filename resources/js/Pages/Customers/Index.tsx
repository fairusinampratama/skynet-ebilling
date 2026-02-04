import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { EditAction, DeleteAction } from '@/Components/TableActions';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/Components/ui/dropdown-menu";
import { MoreHorizontal, Eye, Edit, Trash2 } from "lucide-react";
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';



const getStatusBadge = (status: string) => {
    const variants: Record<string, string> = {
        pending_installation: 'text-blue-500 border-blue-500/20 bg-blue-500/10',
        active: 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10',
        isolated: 'text-red-500 border-red-500/20 bg-red-500/10',
        terminated: 'text-zinc-600 border-zinc-600/20 bg-zinc-600/10',
    };
    const className = variants[status] || 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10';

    return (
        <Badge variant="outline" className={`${className} capitalize border`}>
            {status.replace('_', ' ')}
        </Badge>
    );
};

interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
}

interface Customer {
    id: number;
    internal_id: string;
    code: string;
    name: string;
    address: string;
    pppoe_user: string;
    status: 'pending_installation' | 'active' | 'isolated' | 'terminated';
    is_online: boolean;
    package: Package;
    join_date: string;
    created_at: string;
    invoices?: Array<{
        id: number;
        due_date: string;
        status: string;
    }>;
}



interface Props {
    customers: PaginatedData<Customer>;
    packages: Package[];
    filters: {
        search?: string;
        status?: string;
        package_id?: string;
        sort?: string;
        direction?: 'asc' | 'desc';
    };
}

export default function Index({ customers, packages = [], filters = {} }: Props) {
    const columns: Column<Customer>[] = [
        {
            header: "ID",
            className: "w-[100px]",
            cell: (customer) => (
                <span className="font-mono text-xs text-muted-foreground font-medium">
                    {customer.code || customer.internal_id}
                </span>
            )
        },
        {
            header: "Customer Name",
            accessorKey: "name",
            sortable: true,
            cell: (customer) => (
                <div className="flex flex-col">
                    <div className="flex items-center gap-2">
                        <span className="font-medium text-foreground">{customer.name}</span>
                        {customer.is_online ? (
                            <span className="flex h-2 w-2 rounded-full bg-emerald-500" title="Online" />
                        ) : (
                            <span className="flex h-2 w-2 rounded-full bg-zinc-300" title="Offline" />
                        )}
                    </div>
                    <span className="text-xs text-muted-foreground truncate max-w-[200px]">
                        {customer.address}
                    </span>
                </div>
            )
        },
        {
            header: "Package",
            accessorKey: "package",
            sortable: false,
            cell: (customer) => (
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium text-foreground">
                            {customer.package.name}
                        </span>
                        <Badge variant="secondary" className="text-[10px] h-5 px-1.5 font-normal bg-muted text-muted-foreground hover:bg-muted/80">
                            {customer.package.bandwidth_label}
                        </Badge>
                    </div>
                    <span className="text-xs font-mono text-muted-foreground">
                        Rp {customer.package.price.toLocaleString('id-ID')}
                    </span>
                </div>
            )
        },
        {
            header: "Status",
            accessorKey: "status",
            sortable: true,
            cell: (customer) => getStatusBadge(customer.status)
        },
        {
            header: "Join Date",
            accessorKey: "join_date",
            sortable: true,
            cell: (customer) => (
                <span className="text-sm text-muted-foreground">
                    {new Date(customer.join_date).toLocaleDateString('id-ID', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric'
                    })}
                </span>
            )
        },
        {
            header: "Next Due Date",
            cell: (customer) => (
                customer.invoices && customer.invoices.length > 0 ? (
                    <div className={`text-sm flex flex-col ${new Date(customer.invoices[0].due_date) < new Date() && customer.invoices[0].status === 'unpaid'
                        ? 'text-red-500'
                        : 'text-muted-foreground'
                        }`}>
                        <span className="font-medium">
                            {new Date(customer.invoices[0].due_date).toLocaleDateString('id-ID', {
                                day: 'numeric',
                                month: 'short'
                            })}
                        </span>
                        {new Date(customer.invoices[0].due_date) < new Date() && customer.invoices[0].status === 'unpaid' && (
                            <span className="text-[10px] font-bold uppercase">Overdue</span>
                        )}
                    </div>
                ) : (
                    <span className="text-muted-foreground text-xs italic">No Active Bills</span>
                )
            )
        },
        {
            header: "Actions",
            className: "text-right w-[100px]",
            cell: (customer) => (
                <div className="flex items-center justify-end gap-2" onClick={(e) => e.stopPropagation()}>
                    <EditAction
                        onClick={() => router.visit(route('customers.edit', customer.id))}
                        title="Edit Customer"
                    />
                    <DeleteAction
                        onClick={() => handleDelete(customer.id)}
                        title="Delete Customer"
                    />
                </div>
            )
        },
    ];

    const handleDelete = (id: number) => {
        if (confirm('Are you sure you want to delete this customer?')) {
            router.delete(route('customers.destroy', id));
        }
    };

    const filterConfigs: FilterConfig[] = [
        {
            key: 'package_id',
            placeholder: 'All Packages',
            options: packages.map(pkg => ({ label: pkg.name, value: String(pkg.id) }))
        },
        {
            key: 'status',
            placeholder: 'All Status',
            options: [
                { label: 'Pending', value: 'pending_installation' },
                { label: 'Active', value: 'active' },
                { label: 'Isolated', value: 'isolated' },
                { label: 'Terminated', value: 'terminated' },
            ]
        }
    ];

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Customers
                    </h2>
                    <Link href={route('customers.create')}>
                        <Button className="bg-foreground text-background hover:bg-foreground/90">
                            Add Customer
                        </Button>
                    </Link>
                </div>
            }
        >
            <Head title="Customers" />

            <div className="py-8">
                <DataTable
                    data={customers}
                    columns={columns}
                    filters={filters}
                    title="Customers Directory"
                    description={`Showing ${customers.data.length} of ${customers.total} customers`}
                    searchPlaceholder="Search Name, Phone, Address..."
                    filterConfigs={filterConfigs}
                    routeName="customers.index"
                    onRowClick={(item) => router.visit(route('customers.show', item.id))}
                />
            </div>
        </AuthenticatedLayout>
    );
}
