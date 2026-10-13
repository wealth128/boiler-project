import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { UserRow } from './types';

type DeactivateDialogProps = {
    /** The account to deactivate, or null when closed. */
    user: UserRow | null;
    onClose: () => void;
    onConfirm: (user: UserRow) => void;
    processing: boolean;
};

/**
 * Asks before deactivating: it signs the user out on their next click,
 * which can interrupt someone in the middle of encoding.
 */
export function DeactivateDialog({
    user,
    onClose,
    onConfirm,
    processing,
}: DeactivateDialogProps) {
    return (
        <Dialog
            open={user !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {user && (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                Deactivate {user.username}?
                            </DialogTitle>
                            <DialogDescription>
                                {user.name} can't log in until the account is
                                activated again. If they are signed in now, they
                                are signed out on their next click.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                                disabled={processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => onConfirm(user)}
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Deactivate
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
