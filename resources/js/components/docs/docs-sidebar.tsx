import { Menu, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export interface DocsSidebarItem {
    id: string;
    title: string;
    icon: React.ComponentType<{ className?: string }>;
}

interface DocsSidebarProps {
    items: DocsSidebarItem[];
    activeSection: string;
}

export function DocsSidebar({ items, activeSection }: DocsSidebarProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [isMobile, setIsMobile] = useState(false);

    useEffect(() => {
        const checkMobile = () => {
            setIsMobile(window.innerWidth < 1024);
        };
        checkMobile();
        window.addEventListener('resize', checkMobile);
        return () => window.removeEventListener('resize', checkMobile);
    }, []);

    const scrollToSection = (id: string) => {
        const element = document.getElementById(id);
        if (element) {
            const offset = 80;
            const elementPosition = element.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.scrollY - offset;

            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth',
            });

            if (isMobile) {
                setIsOpen(false);
            }
        }
    };

    const sidebarContent = (
        <nav className="space-y-1">
            {items.map((item) => {
                const Icon = item.icon;
                const isActive = activeSection === item.id;

                return (
                    <button
                        key={item.id}
                        onClick={() => scrollToSection(item.id)}
                        className={cn(
                            'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                            isActive
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4 shrink-0" />
                        <span className="truncate">{item.title}</span>
                    </button>
                );
            })}
        </nav>
    );

    return (
        <>
            {/* Mobile Toggle Button */}
            {isMobile && (
                <Button
                    variant="outline"
                    size="icon"
                    className="fixed bottom-4 right-4 z-50 size-12 rounded-full shadow-lg lg:hidden"
                    onClick={() => setIsOpen(!isOpen)}
                    aria-label="Toggle documentation navigation"
                >
                    {isOpen ? <X className="size-5" /> : <Menu className="size-5" />}
                </Button>
            )}

            {/* Mobile Overlay */}
            {isMobile && isOpen && (
                <div
                    className="fixed inset-0 z-40 bg-black/50 lg:hidden"
                    onClick={() => setIsOpen(false)}
                    aria-hidden="true"
                />
            )}

            {/* Sidebar */}
            <aside
                className={cn(
                    'sticky top-20 h-[calc(100vh-6rem)] w-64 shrink-0 overflow-y-auto',
                    isMobile &&
                        'fixed left-0 top-0 z-50 h-screen w-64 bg-background p-6 shadow-xl transition-transform',
                    isMobile && !isOpen && '-translate-x-full',
                )}
            >
                {isMobile && (
                    <div className="mb-6 flex items-center justify-between">
                        <h2 className="text-lg font-semibold">Documentation</h2>
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => setIsOpen(false)}
                            aria-label="Close navigation"
                        >
                            <X className="size-5" />
                        </Button>
                    </div>
                )}
                {sidebarContent}
            </aside>
        </>
    );
}
