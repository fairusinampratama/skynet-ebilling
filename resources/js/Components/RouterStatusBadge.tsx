import { Badge } from '@/Components/ui/badge';
import { Loader2, CheckCircle2, XCircle } from 'lucide-react';

export type SyncStatus = 'idle' | 'syncing' | 'success' | 'failed';

interface RouterStatusBadgeProps {
    isActive: boolean;
    syncStatus?: SyncStatus;
    cpuLoad?: number | null;
}

export function RouterStatusBadge({
    isActive,
    syncStatus = 'idle',
    cpuLoad
}: RouterStatusBadgeProps) {

    // 1. Handle Syncing State
    if (syncStatus === 'syncing') {
        return (
            <Badge variant="outline" className="h-6 px-3 text-xs whitespace-nowrap text-blue-500 border-blue-500/20 bg-blue-500/10">
                <Loader2 className="mr-1.5 h-3 w-3 animate-spin" />
                Syncing...
            </Badge>
        );
    }

    // 2. Handle Success State
    if (syncStatus === 'success') {
        return (
            <Badge variant="outline" className="h-6 px-3 text-xs whitespace-nowrap text-green-500 border-green-500/20 bg-green-500/10">
                <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                Success
            </Badge>
        );
    }

    // 3. Handle Failed State
    if (syncStatus === 'failed') {
        return (
            <Badge variant="destructive" className="h-6 px-3 text-xs whitespace-nowrap bg-red-500/10 text-red-500 border-red-500/20 hover:bg-red-500/20">
                <XCircle className="mr-1.5 h-3.5 w-3.5" />
                Failed
            </Badge>
        );
    }

    // 4. Default State (Active / Unreachable)
    return (
        <div className="flex items-center gap-2">
            <Badge
                variant={isActive ? "outline" : "secondary"}
                className={`h-6 px-3 text-xs whitespace-nowrap ${isActive ? "text-emerald-500 border-emerald-500/20 bg-emerald-500/10" : "text-muted-foreground"}`}
            >
                {isActive ? 'Active' : 'Unreachable'}
            </Badge>

            {/* Show CPU Load only if Active */}
            {isActive && cpuLoad !== null && cpuLoad !== undefined && (
                <Badge variant="secondary" className="h-6 px-3 text-xs whitespace-nowrap bg-muted/50">
                    CPU: {cpuLoad}%
                </Badge>
            )}
        </div>
    );
}
