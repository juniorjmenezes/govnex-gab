export {
    Avatar,
    AvatarImage,
    AvatarFallback,
    AvatarGroup,
    AvatarGroupCount,
    AvatarBadge,
} from './components/avatar';
export { Badge, badgeVariants } from './components/badge';
export {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from './components/alert';
export {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogMedia,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogTitle,
    AlertDialogTrigger,
} from './components/alert-dialog';
export {
    Breadcrumb,
    BreadcrumbList,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbPage,
    BreadcrumbSeparator,
    BreadcrumbEllipsis,
} from './components/breadcrumb';
export { Button, buttonVariants } from './components/button';
export { Calendar, CalendarDayButton } from './components/calendar';
export type { ChartConfig } from './components/chart';
export {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    ChartLegend,
    ChartLegendContent,
    ChartStyle,
} from './components/chart';
export { Checkbox } from './components/checkbox';
export {
    Collapsible,
    CollapsibleTrigger,
    CollapsibleContent,
} from './components/collapsible';
export {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from './components/card';
export {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogOverlay,
    DialogPortal,
    DialogTitle,
    DialogTrigger,
} from './components/dialog';
export {
    DropdownMenu,
    DropdownMenuPortal,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuLabel,
    DropdownMenuItem,
    DropdownMenuCheckboxItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubTrigger,
    DropdownMenuSubContent,
} from './components/dropdown-menu';
export {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from './components/drawer';
// `FieldLabel`/`FieldError` deste primitivo shadcn colidem em nome com os
// padrões de `patterns/field-label` e `patterns/field-error` — os patterns
// são o que a aplicação de fato usa amplamente (34 e 3 usos, contra 1 deste
// primitivo), então ficam com os nomes "limpos" e este primitivo é
// renomeado no barrel.
export {
    Field,
    FieldLabel as FieldPrimitiveLabel,
    FieldDescription,
    FieldError as FieldPrimitiveError,
    FieldGroup,
    FieldLegend,
    FieldSeparator,
    FieldSet,
    FieldContent,
    FieldTitle,
} from './components/field';
export { Input } from './components/input';
export {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupText,
    InputGroupInput,
    InputGroupTextarea,
} from './components/input-group';
export {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
    InputOTPSeparator,
} from './components/input-otp';
export { Label } from './components/label';
export { MaskedInput } from './components/masked-input';
export {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from './components/pagination';
export {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from './components/popover';
export { Progress } from './components/progress';
export {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectScrollDownButton,
    SelectScrollUpButton,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from './components/select';
export { Separator } from './components/separator';
export {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInput,
    SidebarInset,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSkeleton,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarProvider,
    SidebarRail,
    SidebarSeparator,
    SidebarTrigger,
    useSidebar,
} from './components/sidebar';
export { Skeleton } from './components/skeleton';
export { Sparkline } from './components/sparkline';
export type { SparklineProps } from './components/sparkline';
export { Spinner } from './components/spinner';
export {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from './components/sheet';
export {
    Surface,
    SurfaceDescription,
    SurfaceHeader,
    SurfaceTitle,
    surfaceClasses,
    surfaceInteractiveClasses,
} from './components/surface';
export { Switch } from './components/switch';
export {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from './components/table';
export { Textarea } from './components/textarea';
export { Toggle, toggleVariants } from './components/toggle';
export { ToggleGroup, ToggleGroupItem } from './components/toggle-group';
export {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from './components/tooltip';
export * from './icons';
export { useIsMobile } from './hooks/use-mobile';
export {
    applyMask,
    digitsOnly,
    maskCep,
    maskCnpj,
    maskCpf,
    maskDate,
    maskMaxLength,
    maskPhone,
    maskTime,
} from './lib/masks';
export type { MaskType } from './lib/masks';
export { cn } from './lib/utils';
export { EmptyState } from './patterns/empty-state';
export type { EmptyStateProps } from './patterns/empty-state';
export { DestructiveAlertDialog } from './patterns/destructive-alert-dialog';
export type {
    DestructiveAlertDialogProps,
    DestructiveAnimation,
} from './patterns/destructive-alert-dialog';
export { AttachmentField } from './patterns/attachment-field';
export type { AttachmentCurrentFile } from './patterns/attachment-field';
export { ColorPicker } from './patterns/color-picker';
export { DatePicker } from './patterns/date-picker';
export { DateTimeFieldPair } from './patterns/date-time-field-pair';
export { DropZone } from './patterns/drop-zone';
export { FieldError } from './patterns/field-error';
export { FieldLabel } from './patterns/field-label';
export { TimePicker } from './patterns/time-picker';
export { PageContainer } from './patterns/page-container';
export { PageHeader } from './patterns/page-header';
export type { PageHeaderProps } from './patterns/page-header';
export {
    ScrollableDialogBody,
    ScrollableDialogContent,
    ScrollableDialogFooter,
    ScrollableDialogHeader,
} from './patterns/scrollable-dialog';
export { StatCard, StatCardSkeleton } from './patterns/stat-card';
export type {
    StatCardProgress,
    StatCardProps,
    StatCardTrend,
} from './patterns/stat-card';
export { SectionCard } from './patterns/section-card';
export type { SectionCardProps } from './patterns/section-card';
export {
    TableActionButton,
    tableButtonDestructiveHoverClass,
    tableButtonOutlineHoverClass,
} from './patterns/table-action-button';
export type { TableActionButtonProps } from './patterns/table-action-button';
export { TableGroupRow } from './patterns/table-group-row';
export type { TableGroupRowProps } from './patterns/table-group-row';
export type { IconComponent } from './types/icon';
