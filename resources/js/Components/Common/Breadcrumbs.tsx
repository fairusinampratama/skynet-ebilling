import { Link } from '@inertiajs/react';
import { ChevronRight, Home } from 'lucide-react';
import { usePage } from '@inertiajs/react';

export default function Breadcrumbs() {
    const { url } = usePage();

    // Remove query params and leading slash, then split
    const pathSegments = url.split('?')[0].split('/').filter(Boolean);

    // Don't show on dashboard/home if empty or just dashboard
    if (pathSegments.length === 0 || (pathSegments.length === 1 && pathSegments[0] === 'dashboard')) {
        return (
            <div className="flex items-center text-sm text-muted-foreground">
                <Home className="h-4 w-4 mr-2" />
                <span className="font-medium">Dashboard</span>
            </div>
        );
    }

    return (
        <nav className="flex items-center text-sm text-muted-foreground">
            <Link
                href="/dashboard"
                className="flex items-center hover:text-foreground transition-colors"
                title="Dashboard"
            >
                <Home className="h-4 w-4" />
            </Link>

            {pathSegments.map((segment, index) => {
                // Reconstruct path for this segment
                const path = `/${pathSegments.slice(0, index + 1).join('/')}`;
                const isLast = index === pathSegments.length - 1;

                // Format label: "customer-invoices" -> "Customer Invoices"
                const label = segment
                    .replace(/[-_]/g, ' ')
                    .replace(/^\w/, (c) => c.toUpperCase());

                return (
                    <div key={path} className="flex items-center">
                        <ChevronRight className="h-4 w-4 mx-1 text-muted-foreground/50" />
                        {isLast ? (
                            <span className="font-semibold text-foreground capitalize">
                                {label}
                            </span>
                        ) : (
                            // Don't link if it's a numeric ID in the middle of a path (often not indexable directly without context)
                            // or create logic to handle it. For now, we link everything except numeric IDs to be safe? 
                            // actually, Laravel resource routes usually handle index.
                            <Link
                                href={path}
                                className="hover:text-foreground transition-colors capitalize"
                            >
                                {label}
                            </Link>
                        )}
                    </div>
                );
            })}
        </nav>
    );
}
