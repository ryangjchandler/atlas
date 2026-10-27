import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="12" r="9" fill="none" />
            <path d="M12 3c-2.6 2.4-3.9 5.4-3.9 9s1.3 6.6 3.9 9" fill="none" />
            <path d="M12 3c2.6 2.4 3.9 5.4 3.9 9s-1.3 6.6-3.9 9" fill="none" />
            <path d="M3.6 8.8h16.8M3.6 15.2h16.8" fill="none" />
        </svg>
    );
}
