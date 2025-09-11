import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubItem, SidebarMenuSubButton } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState, useMemo, useCallback, memo } from 'react';
import { useRouteMatch } from '@/hooks/use-page-optimized';

const NavMainComponent = memo(function NavMain({ items = [] }: { items: NavItem[] }) {
    const { isActive, matches } = useRouteMatch();
    const [openItems, setOpenItems] = useState<string[]>(['SPV']); // SPV starts open
    
    const toggleItem = useCallback((title: string) => {
        setOpenItems(prev => 
            prev.includes(title) 
                ? prev.filter(item => item !== title)
                : [...prev, title]
        );
    }, []);

    const isItemActive = useCallback((item: NavItem): boolean => {
        // For parent items with children, check if any child is active
        if (item.children) {
            return item.children.some(child => isActive(child.href));
        }
        // For items without children, check direct match
        return isActive(item.href);
    }, [isActive]);

    // Memoize rendered items to prevent unnecessary re-renders
    const renderedItems = useMemo(() => {
        return items.map((item) => {
            const isOpen = openItems.includes(item.title);
            const itemIsActive = isItemActive(item);
            const hasChildren = item.children && item.children.length > 0;

            return {
                ...item,
                isOpen,
                itemIsActive,
                hasChildren,
            };
        });
    }, [items, openItems, isItemActive]);

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Platform</SidebarGroupLabel>
            <SidebarMenu>
                {renderedItems.map((item) => {
                    const { isOpen, itemIsActive, hasChildren } = item;

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton 
                                asChild={!hasChildren} 
                                isActive={itemIsActive && !hasChildren}
                                tooltip={{ children: item.title }}
                                onClick={hasChildren ? () => toggleItem(item.title) : undefined}
                                size="default"
                                className={hasChildren ? 'cursor-pointer h-8 text-sm' : ''}
                            >
                                {hasChildren ? (
                                    <div className="flex w-full items-center gap-2">
                                        {item.icon && <item.icon className="h-4 w-4 shrink-0" />}
                                        <span className="flex-1">{item.title}</span>
                                        <ChevronRight 
                                            className={`h-4 w-4 shrink-0 transition-transform duration-200 ${
                                                isOpen ? 'rotate-90' : ''
                                            }`} 
                                        />
                                    </div>
                                ) : (
                                    <Link href={item.href} prefetch>
                                        {item.icon && <item.icon className="h-4 w-4 shrink-0" />}
                                        <span>{item.title}</span>
                                    </Link>
                                )}
                            </SidebarMenuButton>
                            
                            {hasChildren && isOpen && (
                                <SidebarMenuSub>
                                    {item.children!.map((child) => (
                                        <SidebarMenuSubItem key={child.title}>
                                            <SidebarMenuSubButton 
                                                asChild 
                                                size="md"
                                                isActive={
                                                    // Exact matching for SPV submenus
                                                    child.href === '/spv/requests' 
                                                        ? isActive('/spv/requests')
                                                        : child.href === '/spv'
                                                            ? isActive('/spv', true)
                                                            : isActive(child.href)
                                                }
                                                className="h-8 text-sm"
                                            >
                                                <Link href={child.href} prefetch>
                                                    {child.icon && <child.icon className="h-4 w-4 shrink-0" />}
                                                    <span>{child.title}</span>
                                                </Link>
                                            </SidebarMenuSubButton>
                                        </SidebarMenuSubItem>
                                    ))}
                                </SidebarMenuSub>
                            )}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
});

export const NavMain = NavMainComponent;
