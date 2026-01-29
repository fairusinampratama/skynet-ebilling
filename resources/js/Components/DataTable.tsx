import { useState, useEffect, ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowUpDown, ArrowUp, ArrowDown, X, Loader2, ChevronLeft, ChevronRight } from "lucide-react";

// Helper for debounce
function useDebounce<T>(value: T, delay: number): T {
    const [debouncedValue, setDebouncedValue] = useState(value);
    useEffect(() => {
        const handler = setTimeout(() => {
            setDebouncedValue(value);
        }, delay);
        return () => {
            clearTimeout(handler);
        };
    }, [value, delay]);
    return debouncedValue;
}

// Generic Types
export interface Column<T> {
    header: string;
    accessorKey?: keyof T;
    cell?: (item: T) => ReactNode;
    sortable?: boolean;
    className?: string; // For applying column specific styles like width or alignment
}

export interface FilterOption {
    label: string;
    value: string;
}

export interface FilterConfig {
    key: string;
    placeholder: string;
    options: FilterOption[];
}

export interface PaginatedData<T> {
    data: T[];
    path: string;
    links: {
        url: string | null;
        label: string;
        active: boolean;
    }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

interface DataTableProps<T> {
    data: PaginatedData<T>;
    columns: Column<T>[];
    title: string;
    description?: string;
    resourceName?: string; // e.g. "customers" for route generation if needed, mostly for logging/debugging
    filters?: Record<string, any>; // Current filter processing from backend
    searchPlaceholder?: string;
    filterConfigs?: FilterConfig[]; // Dropdown filters configuration
    actions?: ReactNode; // Slot for "Add Button" etc.
    routeName: string; // Base route name for router.get() e.g. "customers.index"
}

export default function DataTable<T extends { id: number | string }>({
    data,
    columns,
    title,
    description,
    filters = {},
    searchPlaceholder = "Search...",
    filterConfigs = [],
    actions,
    routeName
}: DataTableProps<T>) {
    const safeFilters = Array.isArray(filters) ? {} : (filters || {});

    // State
    const [search, setSearch] = useState(safeFilters.search || '');
    const [activeFilters, setActiveFilters] = useState<Record<string, string>>(() => {
        const initial: Record<string, string> = {};
        filterConfigs.forEach(config => {
            initial[config.key] = safeFilters[config.key] || 'all';
        });
        return initial;
    });

    const [sortField, setSortField] = useState<string>(
        (typeof safeFilters.sort === 'string') ? safeFilters.sort : 'created_at'
    );
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>(
        safeFilters.direction === 'asc' ? 'asc' : 'desc'
    );
    const [isLoading, setIsLoading] = useState(false);

    const debouncedSearch = useDebounce(search, 350);

    // Effect to trigger search/filter
    useEffect(() => {
        const params: Record<string, any> = {
            search: debouncedSearch,
            sort: sortField,
            direction: sortDirection,
        };

        // Add active filters excluding 'all'
        Object.keys(activeFilters).forEach(key => {
            if (activeFilters[key] !== 'all') {
                params[key] = activeFilters[key];
            }
        });

        setIsLoading(true);
        router.get(
            route(routeName),
            params,
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setIsLoading(false)
            }
        );
    }, [debouncedSearch, activeFilters, sortField, sortDirection, routeName]);

    // Handlers
    const handleSort = (field: string) => {
        if (sortField === field) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortField(field);
            setSortDirection('asc');
        }
    };

    const handleFilterChange = (key: string, value: string) => {
        setActiveFilters(prev => ({ ...prev, [key]: value }));
    };

    const handleReset = () => {
        setSearch('');
        const resetFilters: Record<string, string> = {};
        filterConfigs.forEach(config => {
            resetFilters[config.key] = 'all';
        });
        setActiveFilters(resetFilters);
        setSortField('created_at');
        setSortDirection('desc');
    };

    const SortIcon = ({ field }: { field: string }) => {
        if (sortField !== field) return <ArrowUpDown className="ml-2 h-3 w-3 text-muted-foreground/50" />;
        return sortDirection === 'asc'
            ? <ArrowUp className="ml-2 h-3 w-3 text-foreground" />
            : <ArrowDown className="ml-2 h-3 w-3 text-foreground" />;
    };

    return (
        <div className="space-y-6">
            {/* Filter Bar */}
            <div className="flex flex-col sm:flex-row gap-4 p-4 rounded-xl border border-border bg-card shadow-sm transition-all">
                <div className="flex-1 relative">
                    <Input
                        placeholder={searchPlaceholder}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-full bg-background border-input focus-visible:ring-ring pl-10"
                    />
                    <div className="absolute left-3 top-2.5 text-muted-foreground pointer-events-none">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="lucide lucide-search"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
                    </div>
                    {search && (
                        <button onClick={() => setSearch('')} className="absolute right-3 top-2.5 text-muted-foreground hover:text-foreground">
                            <X className="h-4 w-4" />
                        </button>
                    )}
                </div>

                {filterConfigs.map(config => (
                    <Select
                        key={config.key}
                        value={activeFilters[config.key]}
                        onValueChange={(val) => handleFilterChange(config.key, val)}
                    >
                        <SelectTrigger className="w-[160px] bg-background border-input text-foreground">
                            <SelectValue placeholder={config.placeholder} />
                        </SelectTrigger>
                        <SelectContent className="bg-background border-border">
                            <SelectItem value="all" className="text-foreground focus:bg-accent focus:text-accent-foreground">{config.placeholder}</SelectItem>
                            {config.options.map(opt => (
                                <SelectItem key={opt.value} value={opt.value} className="text-foreground focus:bg-accent focus:text-accent-foreground">{opt.label}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ))}

                <Button
                    variant="ghost"
                    onClick={handleReset}
                    className="px-3 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                    title="Reset Filters"
                >
                    Reset
                </Button>

                {actions && (
                    <div className="sm:ml-auto pl-2 border-l border-border/50">
                        {actions}
                    </div>
                )}
            </div>

            {/* Table Card */}
            <Card className="border-border bg-card shadow-sm">
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle>{title}</CardTitle>
                            {description && (
                                <CardDescription className="mt-1.5 flex items-center gap-2">
                                    {description}
                                    {isLoading && <Loader2 className="h-3 w-3 animate-spin" />}
                                </CardDescription>
                            )}
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div className="rounded-md border border-border overflow-hidden">
                        <Table>
                            <TableHeader className="bg-muted/50">
                                <TableRow className="border-border hover:bg-muted/50">
                                    {columns.map((col, idx) => (
                                        <TableHead key={idx} className={col.className}>
                                            {col.sortable && col.accessorKey ? (
                                                <Button
                                                    variant="ghost"
                                                    onClick={() => handleSort(col.accessorKey as string)}
                                                    className="-ml-4 h-8 data-[state=open]:bg-accent text-xs uppercase font-medium"
                                                >
                                                    {col.header}
                                                    <SortIcon field={col.accessorKey as string} />
                                                </Button>
                                            ) : (
                                                <span className="text-xs uppercase font-medium text-muted-foreground">{col.header}</span>
                                            )}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={columns.length} className="h-48 text-center">
                                            <div className="flex flex-col items-center justify-center text-muted-foreground">
                                                <p className="text-lg font-medium">No results found</p>
                                                <p className="text-sm">Try adjusting your search or filters</p>
                                                <Button variant="link" onClick={handleReset} className="mt-2">
                                                    Clear all filters
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    data.data.map((item) => (
                                        <TableRow key={item.id} className="group hover:bg-muted/50 border-border transition-colors">
                                            {columns.map((col, idx) => (
                                                <TableCell key={idx} className={col.className}>
                                                    {col.cell
                                                        ? col.cell(item)
                                                        : (col.accessorKey ? (item[col.accessorKey] as ReactNode) : null)
                                                    }
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pagination */}
                    <div className="mt-6 flex items-center justify-between border-t border-border pt-4">
                        <div className="text-sm text-muted-foreground">
                            Showing <span className="font-medium text-foreground">{data.from || 0}</span> to <span className="font-medium text-foreground">{data.to || 0}</span> of <span className="font-medium text-foreground">{data.total}</span> records
                        </div>
                        <div className="flex gap-1.5 items-center">
                            <span className="text-sm text-muted-foreground mr-2">Rows per page:</span>
                            <Select
                                value={String(data.per_page)}
                                onValueChange={(val) => {
                                    router.get(data.path, { ...filters, limit: val }, {
                                        preserveState: true,
                                        preserveScroll: true
                                    });
                                }}
                            >
                                <SelectTrigger className="h-8 w-[70px]">
                                    <SelectValue placeholder={String(data.per_page)} />
                                </SelectTrigger>
                                <SelectContent>
                                    {[10, 25, 50, 100].map((size) => (
                                        <SelectItem key={size} value={String(size)}>
                                            {size}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        const prevUrl = data.links[0]?.url;
                                        if (prevUrl) {
                                            router.get(prevUrl, { limit: data.per_page }, {
                                                preserveScroll: true,
                                                preserveState: true,
                                            });
                                        }
                                    }}
                                    disabled={data.current_page === 1}
                                    className="px-2"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>

                                <span className="text-sm font-medium text-muted-foreground min-w-[100px] text-center">
                                    Page {data.current_page} of {data.last_page}
                                </span>

                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        const nextUrl = data.links[data.links.length - 1]?.url;
                                        if (nextUrl) {
                                            router.get(nextUrl, { limit: data.per_page }, {
                                                preserveScroll: true,
                                                preserveState: true,
                                            });
                                        }
                                    }}
                                    disabled={data.current_page === data.last_page}
                                    className="px-2"
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
