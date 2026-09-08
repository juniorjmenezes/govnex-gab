import type { SVGProps } from 'react';

/**
 * Marca do GOVNEX. O traçado usa `currentColor`, então a cor vem da classe
 * de texto aplicada no ponto de uso (tema claro/escuro e cor da organização).
 */
export function AppLogoMark(props: SVGProps<SVGSVGElement>) {
    return (
        <svg
            viewBox="0 0 203.15 204.7"
            fill="currentColor"
            xmlns="http://www.w3.org/2000/svg"
            {...props}
        >
            <path d="M198.87,90.77v15.1c-1.02,17.07-6.72,33.46-15.82,47.66-16.29,25.42-43.48,43.73-74.19,45.93C51.81,203.54,4.28,158.51,4.28,102.33c0-26.86,10.88-51.21,28.52-68.82C50.42,15.88,74.79,4.99,101.66,4.99h11.42v55.36h-11.42c-11.61,0-22.12,4.69-29.7,12.28-8.37,8.42-13.22,20.28-12.15,33.31,1.75,20.76,19.2,37.45,40,38.34,19.05.82,35.43-11.05,41.4-27.84l-28.96-25.67h86.62Z" />
            <rect x="143.96" y="5" width="54.91" height="54.91" />
        </svg>
    );
}
