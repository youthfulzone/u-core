import React from 'react';
import { Button } from '@/components/ui/button';
import { CheckCircle, AlertCircle, Info, X } from 'lucide-react';

interface Notification {
    id: string;
    type: 'success' | 'error' | 'info';
    message: string;
}

interface NotificationsProps {
    notifications: Notification[];
    onRemove: (id: string) => void;
}

export default function Notifications({ notifications, onRemove }: NotificationsProps) {
    if (notifications.length === 0) return null;

    return (
        <div className="fixed top-4 right-4 z-50 space-y-2">
            {notifications.map((notification) => (
                <div
                    key={notification.id}
                    className={`flex items-center gap-2 p-3 rounded-lg shadow-lg max-w-sm ${
                        notification.type === 'success' ? 'bg-green-50 border border-green-200 text-green-800' :
                        notification.type === 'error' ? 'bg-red-50 border border-red-200 text-red-800' :
                        'bg-blue-50 border border-blue-200 text-blue-800'
                    }`}
                >
                    {notification.type === 'success' && <CheckCircle className="h-4 w-4" />}
                    {notification.type === 'error' && <AlertCircle className="h-4 w-4" />}
                    {notification.type === 'info' && <Info className="h-4 w-4" />}
                    <span className="text-sm flex-1">{notification.message}</span>
                    <Button
                        onClick={() => onRemove(notification.id)}
                        variant="ghost"
                        size="sm"
                        className="h-auto p-0.5"
                    >
                        <X className="h-3 w-3" />
                    </Button>
                </div>
            ))}
        </div>
    );
}