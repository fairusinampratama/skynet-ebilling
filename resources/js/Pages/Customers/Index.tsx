import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { MoreHorizontal, Eye, Edit, Trash2 } from "lucide-react";
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';



const getStatusBadge = (status: string) => {
    const variants: Record<string, string> = {
        active: 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10',
        suspended: 'text-orange-500 border-orange-500/20 bg-orange-500/10',
        isolated: 'text-red-500 border-red-500/20 bg-red-500/10',
        offboarding: 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10',
    };
    const className = variants[status] || 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10';

    return (
        <Badge variant="outline" className={`${className} capitalize border`}>
            {status}
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
    status: 'active' | 'suspended' | 'isolated' | 'offboarding';
    is_online: boolean;
    package: Package;
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
            className: "text-right",
            cell: (customer) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" className="h-8 w-8 p-0">
                            <span className="sr-only">Open menu</span>
                            <MoreHorizontal className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => router.visit(route('customers.show', customer.id))}>
                            <Eye className="mr-2 h-4 w-4" />
                            View Details
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => router.visit(route('customers.edit', customer.id))}>
                            <Edit className="mr-2 h-4 w-4" />
                            Edit Customer
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            className="text-red-600 focus:text-red-600"
                            onClick={() => {
                                if (confirm('Are you sure you want to delete this customer?')) {
                                    router.delete(route('customers.destroy', customer.id));
                                }
                            }}
                        >
                            <Trash2 className="mr-2 h-4 w-4" />
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )
        }
    ];

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
                { label: 'Active', value: 'active' },
                { label: 'Suspended', value: 'suspended' },
                { label: 'Isolated', value: 'isolated' },
                { label: 'Offboarding', value: 'offboarding' },
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
                    description={`Managing ${customers.total} registered subscribers`}
                    searchPlaceholder="Search Name, ID, Address..."
                    filterConfigs={filterConfigs}
                    routeName="customers.index"
                    actions={
                        <Link href={route('customers.create')}>
                            <Button className="bg-foreground text-background hover:bg-foreground/90">
                                Add Customer
                            </Button>
                        </Link>
                    }
                />
            </div>
        </AuthenticatedLayout>
    );
}
