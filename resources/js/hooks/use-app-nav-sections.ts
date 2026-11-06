import { usePage } from '@inertiajs/react';
import {
    Buildings2Icon,
    BuildingsIcon,
    CalendarDateIcon,
    CalendarIcon,
    ChatRoundDotsIcon,
    ClipboardListIcon,
    FeedIcon,
    HandShakeIcon,
    LibraryIcon,
    MapIcon,
    PaletteIcon,
    PlugCircleIcon,
    PresentationGraphIcon,
    RadarIcon,
    RefreshCircleIcon,
    ServerIcon,
    ShieldCheckIcon,
    StructureIcon,
    UsersGroupRoundedIcon,
    UsersGroupTwoRoundedIcon,
    WidgetIcon,
} from '@/components/icons';
import { contextualUrl } from '@/lib/entity-context';
import { hasModule } from '@/lib/modules';
import type {
    Auth,
    EntidadeModuleCode,
    GabineteModuleCode,
    NavItem,
} from '@/types';

/**
 * `module`: exige o módulo no gabinete. `entidadeModule`: exige só o módulo
 * na entidade — para o que a Câmara compartilha com todos os gabinetes dela
 * (ex.: comissões, que todo vereador lê sem ativar nada no gabinete).
 */
type ModuleNavItem = NavItem & {
    module?: GabineteModuleCode;
    entidadeModule?: EntidadeModuleCode;
};

const overviewItems: ModuleNavItem[] = [
    { title: 'Visão geral', href: '/dashboard', icon: WidgetIcon },
    {
        title: 'Painel político',
        href: '/painel-politico',
        icon: PresentationGraphIcon,
        module: 'POLITICA',
    },
    {
        title: 'Base de Conhecimento',
        href: '/conhecimento',
        icon: LibraryIcon,
        module: 'BASE_CONHECIMENTO',
    },
    {
        title: 'Comissões',
        href: '/comissoes',
        icon: UsersGroupTwoRoundedIcon,
        entidadeModule: 'SECRETARIA_LEGISLATIVA',
    },
];
const tenantServiceItems: ModuleNavItem[] = [
    {
        title: 'Demandas',
        href: '/demandas',
        icon: ClipboardListIcon,
        module: 'DEMANDAS',
    },
    {
        title: 'Atendimentos',
        href: '/atendimentos',
        icon: HandShakeIcon,
        module: 'ATENDIMENTOS',
    },
    {
        title: 'Cidadãos',
        href: '/cidadaos',
        icon: UsersGroupRoundedIcon,
        module: 'RELACIONAMENTO',
    },
    {
        title: 'Agenda',
        href: '/agenda',
        icon: CalendarIcon,
        module: 'AGENDA',
    },
    {
        title: 'Eventos',
        href: '/eventos',
        icon: CalendarDateIcon,
        module: 'EVENTOS',
    },
];
const mapItems: ModuleNavItem[] = [
    {
        title: 'Mapa de eleitores',
        href: '/eleitores/mapa',
        icon: MapIcon,
        module: 'POLITICA',
    },
    {
        title: 'Mapa de prospecção',
        href: '/eleitores/prospeccao',
        icon: RadarIcon,
        module: 'POLITICA',
    },
];

export type NavSection = {
    label: string;
    items: NavItem[];
};

/**
 * Centraliza o cálculo dos grupos de navegação do app-shell (sidebar) para
 * que a mesma lista possa alimentar tanto a sidebar quanto a busca rápida do
 * header, sem duplicar a lógica de contexto (root/plataforma vs. tenant) e
 * de módulos habilitados.
 */
export function useAppNavSections(): NavSection[] {
    const { auth } = usePage<{ auth: Auth }>().props;
    const isRoot = auth.user.role === 'root';
    const hasGabineteContext = auth.context.gabinete !== null;
    const isPlatformOverview = isRoot && !hasGabineteContext;
    const withContext = (item: ModuleNavItem): ModuleNavItem => ({
        ...item,
        href: contextualUrl(auth, String(item.href)),
    });

    const panelItems: NavItem[] = (
        isPlatformOverview
            ? [
                  {
                      title: 'Visão geral',
                      href: '/dashboard',
                      icon: WidgetIcon,
                  },
              ]
            : overviewItems.filter(
                  (item) =>
                      (!item.module || hasModule(auth.modules, item.module)) &&
                      (!item.entidadeModule ||
                          hasModule(
                              auth.entidade_modules,
                              item.entidadeModule,
                          )),
              )
    ).map(withContext);

    // Visão geral da plataforma (root sem gabinete): os itens ficam em blocos
    // por assunto, para integrações e dados externos não se misturarem.
    const platformSections: NavSection[] = isPlatformOverview
        ? [
              {
                  label: 'Estrutura',
                  items: [
                      {
                          title: 'Entidades',
                          href: '/entidades',
                          icon: StructureIcon,
                      },
                      {
                          title: 'Gabinetes',
                          href: '/admin/gabinetes',
                          icon: BuildingsIcon,
                      },
                  ],
              },
              {
                  label: 'Dados políticos',
                  items: [
                      {
                          title: 'Eleições',
                          href: '/admin/eleicoes',
                          icon: CalendarDateIcon,
                      },
                      {
                          title: 'Pesquisas',
                          href: '/admin/pesquisas-eleitorais',
                          icon: Buildings2Icon,
                      },
                      {
                          title: 'Partidos',
                          href: '/admin/cores-partidos',
                          icon: PaletteIcon,
                      },
                  ],
              },
              {
                  label: 'Integrações',
                  items: [
                      {
                          title: 'PollingData',
                          href: '/admin/sincronizacao-politica',
                          icon: RefreshCircleIcon,
                      },
                      {
                          title: 'RSS',
                          href: '/admin/fontes-rss',
                          icon: FeedIcon,
                      },
                      {
                          title: 'WhatsApp',
                          href: '/admin/whatsapp',
                          icon: ChatRoundDotsIcon,
                      },
                      {
                          title: 'GOVNEX API',
                          href: '/admin/govnex-api',
                          icon: PlugCircleIcon,
                      },
                  ],
              },
              {
                  label: 'Plataforma',
                  items: [
                      {
                          title: 'Base de Conhecimento',
                          href: '/admin/conhecimento',
                          icon: LibraryIcon,
                      },
                      {
                          title: 'Usuários root',
                          href: '/admin/usuarios',
                          icon: ShieldCheckIcon,
                      },
                      {
                          title: 'Diagnóstico do sistema',
                          href: '/admin/sistema',
                          icon: ServerIcon,
                      },
                  ],
              },
          ]
        : [];

    const serviceItems: NavItem[] = isRoot
        ? isPlatformOverview
            ? []
            : tenantServiceItems
                  .filter(
                      (item) =>
                          !item.module || hasModule(auth.modules, item.module),
                  )
                  .map(withContext)
        : tenantServiceItems
              .filter(
                  (item) =>
                      !item.module || hasModule(auth.modules, item.module),
              )
              .map(withContext);

    const visibleMapItems: NavItem[] = isPlatformOverview
        ? []
        : mapItems
              .filter(
                  (item) =>
                      !item.module || hasModule(auth.modules, item.module),
              )
              .map(withContext);

    const platformReturnItems: NavItem[] =
        isRoot && hasGabineteContext
            ? [
                  {
                      title: 'Entidades',
                      href: '/entidades',
                      icon: StructureIcon,
                  },
                  {
                      title: 'Gabinetes',
                      href: '/admin/gabinetes',
                      icon: BuildingsIcon,
                  },
              ]
            : [];

    const entidadeItems: NavItem[] = auth.context.entidade
        ? [
              {
                  title: 'Entidade',
                  href: auth.context.entidade_base_url ?? '/entidades',
                  icon: Buildings2Icon,
              },
          ]
        : [];

    return [
        { label: 'Painel', items: panelItems },
        ...(isPlatformOverview
            ? platformSections
            : [
                  {
                      label: isRoot ? 'Plataforma' : 'Atendimento',
                      items: serviceItems,
                  },
              ]),
        ...(visibleMapItems.length > 0
            ? [{ label: 'Mapas', items: visibleMapItems }]
            : []),
        ...(platformReturnItems.length > 0
            ? [{ label: 'Plataforma', items: platformReturnItems }]
            : []),
        ...(entidadeItems.length > 0
            ? [{ label: 'Entidade', items: entidadeItems }]
            : []),
    ];
}
