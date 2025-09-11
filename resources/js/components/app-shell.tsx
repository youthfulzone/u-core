import { SidebarProvider } from '@/components/ui/sidebar';
import { SharedData } from '@/types';
import { memo } from 'react';
import { usePageOptimized } from '@/hooks/use-page-optimized';

interface AppShellProps {
    children: React.ReactNode;
    variant?: 'header' | 'sidebar';
}

const AppShellComponent = memo(function AppShell({ children, variant = 'header' }: AppShellProps) {
    const { props } = usePageOptimized<SharedData>();
    const isOpen = props.sidebarOpen;

    if (variant === 'header') {
        return <div className="flex min-h-screen w-full flex-col">{children}</div>;
    }

    return <SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>;
});

export const AppShell = AppShellComponent;
