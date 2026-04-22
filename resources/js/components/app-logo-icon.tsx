import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 130 130"
        >
            <g clip-path="url(#a)">
                <path
                    fill="#4f46e5"
                    d="M102 0H28C12.536 0 0 12.536 0 28v74c0 15.464 12.536 28 28 28h74c15.464 0 28-12.536 28-28V28c0-15.464-12.536-28-28-28"
                />
                <path
                    stroke="#e1dfff"
                    stroke-linecap="round"
                    stroke-width="12"
                    d="M88 47a30 30 0 1 0 0 36"
                />
                <path fill="#e1dfff" d="M98 70a5 5 0 1 0 0-10 5 5 0 0 0 0 10" />
            </g>
            <defs>
                <clipPath id="a">
                    <path fill="#fff" d="M0 0h130v130H0z" />
                </clipPath>
            </defs>
        </svg>
    );
}
