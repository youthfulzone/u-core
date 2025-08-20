import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubItem, SidebarMenuSubButton } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage();
    const [openItems, setOpenItems] = useState<string[]>(['SPV']); // SPV starts open
    
    const toggleItem = (title: string) => {
        setOpenItems(prev => 
            prev.includes(title) 
                ? prev.filter(item => item !== title)
                : [...prev, title]
        );
    };

    const isItemActive = (item: NavItem): boolean => {
        // For parent items with children, check if any child is active
        if (item.children) {
            return item.children.some(child => page.url === child.href || page.url.startsWith(child.href + '/'));
        }
        // For items without children, check direct match
        return page.url === item.href || page.url.startsWith(item.href + '/');
    };

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Platform</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => {
                    const isOpen = openItems.includes(item.title);
                    const isActive = isItemActive(item);
                    const hasChildren = item.children && item.children.length > 0;

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton 
                                asChild={!hasChildren} 
                                isActive={isActive && !hasChildren}
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
                                                        ? page.url.startsWith('/spv/requests')
                                                        : child.href === '/spv'
                                                            ? page.url === '/spv'
                                                            : page.url === child.href || page.url.startsWith(child.href + '/')
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
}
