import type { ComponentType, SVGProps } from 'react';

/**
 * Componente de ícone genérico: aceita tanto ícones do lucide-react quanto
 * do @solar-icons/react (ou qualquer componente SVG compatível).
 */
export type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;
