import Icon from '@/Components/Material/Icon';

/**
 * Material Symbols under the component names the app used from @heroicons/react/24/outline,
 * so `<ShieldCheckIcon className="h-5 w-5" />` keeps working. Size comes from the w-/h-/size-
 * utility in className (Tailwind spacing × 4px); add `fill` for the filled glyph.
 */
const SPACING = /(?:^|\s)(?:size|w|h)-(?:\[(\d+(?:\.\d+)?)px\]|(\d+(?:\.5)?))(?=\s|$)/;

function sizeFrom(className = '') {
    const m = className.match(SPACING);
    if (!m) return 20;
    return m[1] ? Number(m[1]) : Number(m[2]) * 4;
}

function symbol(name) {
    const C = ({ className = '', fill = false, title, ...rest }) => (
        <Icon name={name} size={sizeFrom(className)} fill={fill} label={title} className={className} {...rest} />
    );
    C.displayName = `Symbol(${name})`;
    return C;
}

export const ArchiveBoxIcon = symbol('inventory_2');
export const ArrowDownTrayIcon = symbol('download');
export const ArrowPathIcon = symbol('refresh');
export const ArrowRightIcon = symbol('arrow_forward');
export const ArrowTopRightOnSquareIcon = symbol('open_in_new');
export const ArrowTrendingDownIcon = symbol('trending_down');
export const ArrowTrendingUpIcon = symbol('trending_up');
export const ArrowUpCircleIcon = symbol('arrow_circle_up');
export const ArrowUpIcon = symbol('arrow_upward');
export const ArrowUpTrayIcon = symbol('upload');
export const ArrowsRightLeftIcon = symbol('swap_horiz');
export const Bars3Icon = symbol('menu');
export const BeakerIcon = symbol('science');
export const BellAlertIcon = symbol('notifications_active');
export const BellIcon = symbol('notifications');
export const BoltIcon = symbol('bolt');
export const BookOpenIcon = symbol('menu_book');
export const BriefcaseIcon = symbol('work');
export const BugAntIcon = symbol('bug_report');
export const BuildingLibraryIcon = symbol('account_balance');
export const BuildingOffice2Icon = symbol('apartment');
export const BuildingOfficeIcon = symbol('domain');
export const CameraIcon = symbol('photo_camera');
export const ChartBarIcon = symbol('bar_chart');
export const ChatBubbleLeftIcon = symbol('chat_bubble');
export const ChatBubbleLeftRightIcon = symbol('forum');
export const CheckBadgeIcon = symbol('verified');
export const CheckCircleIcon = symbol('check_circle');
export const CheckIcon = symbol('check');
export const ChevronDoubleLeftIcon = symbol('keyboard_double_arrow_left');
export const ChevronDoubleRightIcon = symbol('keyboard_double_arrow_right');
export const ChevronDownIcon = symbol('expand_more');
export const ChevronRightIcon = symbol('chevron_right');
export const ChevronUpDownIcon = symbol('unfold_more');
export const ChevronUpIcon = symbol('expand_less');
export const CircleStackIcon = symbol('database');
export const ClipboardDocumentCheckIcon = symbol('assignment_turned_in');
export const ClipboardDocumentIcon = symbol('assignment');
export const ClipboardIcon = symbol('content_paste');
export const ClockIcon = symbol('schedule');
export const CloudArrowUpIcon = symbol('cloud_upload');
export const CloudIcon = symbol('cloud');
export const CodeBracketIcon = symbol('code');
export const Cog6ToothIcon = symbol('settings');
export const CogIcon = symbol('settings');
export const CommandLineIcon = symbol('terminal');
export const CpuChipIcon = symbol('memory');
export const CreditCardIcon = symbol('credit_card');
export const CubeIcon = symbol('deployed_code');
export const CubeTransparentIcon = symbol('view_in_ar');
export const CurrencyDollarIcon = symbol('payments');
export const DevicePhoneMobileIcon = symbol('smartphone');
export const DocumentArrowDownIcon = symbol('file_save');
export const DocumentArrowUpIcon = symbol('upload_file');
export const DocumentCheckIcon = symbol('task');
export const DocumentIcon = symbol('description');
export const DocumentMagnifyingGlassIcon = symbol('find_in_page');
export const DocumentTextIcon = symbol('article');
export const EnvelopeIcon = symbol('mail');
export const EnvelopeOpenIcon = symbol('drafts');
export const ExclamationCircleIcon = symbol('error');
export const ExclamationTriangleIcon = symbol('warning');
export const EyeIcon = symbol('visibility');
export const FingerPrintIcon = symbol('fingerprint');
export const FireIcon = symbol('local_fire_department');
export const FunnelIcon = symbol('filter_alt');
export const GlobeAltIcon = symbol('public');
export const HomeIcon = symbol('home');
export const IdentificationIcon = symbol('badge');
export const InboxIcon = symbol('inbox');
export const InformationCircleIcon = symbol('info');
export const LinkIcon = symbol('link');
export const LockClosedIcon = symbol('lock');
export const MagnifyingGlassIcon = symbol('search');
export const MapIcon = symbol('map');
export const MapPinIcon = symbol('location_on');
export const NewspaperIcon = symbol('newspaper');
export const PaperAirplaneIcon = symbol('send');
export const PaperClipIcon = symbol('attach_file');
export const PencilIcon = symbol('edit');
export const PencilSquareIcon = symbol('edit_square');
export const PhoneIcon = symbol('call');
export const PlayIcon = symbol('play_arrow');
export const PlusCircleIcon = symbol('add_circle');
export const PlusIcon = symbol('add');
export const PowerIcon = symbol('power_settings_new');
export const PrinterIcon = symbol('print');
export const PuzzlePieceIcon = symbol('extension');
export const QuestionMarkCircleIcon = symbol('help');
export const RectangleGroupIcon = symbol('dashboard');
export const ScaleIcon = symbol('balance');
export const ServerIcon = symbol('dns');
export const ServerStackIcon = symbol('storage');
export const ShieldCheckIcon = symbol('verified_user');
export const ShieldExclamationIcon = symbol('gpp_maybe');
export const SparklesIcon = symbol('auto_awesome');
export const Squares2X2Icon = symbol('grid_view');
export const StarIcon = symbol('star');
export const TableCellsIcon = symbol('table');
export const TicketIcon = symbol('confirmation_number');
export const TrashIcon = symbol('delete');
export const UserCircleIcon = symbol('account_circle');
export const UserGroupIcon = symbol('groups');
export const UserIcon = symbol('person');
export const UsersIcon = symbol('group');
export const XCircleIcon = symbol('cancel');
export const XMarkIcon = symbol('close');
