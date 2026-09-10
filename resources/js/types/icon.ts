import type { ComponentType, SVGProps } from 'react';

/**
 * Componente de ícone genérico: aceita os ícones de `components/icons`
 * do @solar-icons/react (ou qualquer componente SVG compatível).
 */
export type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;
