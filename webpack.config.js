const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CopyWebpackPlugin = require('copy-webpack-plugin');

module.exports = {
    ...defaultConfig,
    entry: {
        'query-loop-facet/index': path.resolve(__dirname, 'resources/assets/js/gutenberg/blocks/query-loop-facet/index.jsx'),
        'query-loop/index': path.resolve(__dirname, 'resources/assets/js/gutenberg/blocks/query-loop/index.js'),
        'main': path.resolve(__dirname, 'resources/assets/main.js'),
        'front-facets': [
            path.resolve(__dirname, 'resources/assets/js/frontend/index.js'),
            path.resolve(__dirname, 'resources/assets/css/front-facets.css')
        ],
    },
    output: {
        path: path.resolve(__dirname, 'build'),
        filename: '[name].js',
    },
    module: {
        ...defaultConfig.module,
        rules: [
            ...defaultConfig.module.rules.filter(rule => !rule.test || !rule.test.test('.css')), // Supprime les règles CSS existantes
            {
                test: /\.css$/,
                use: [
                    MiniCssExtractPlugin.loader, // Remplace style-loader
                    'css-loader',
                    {
                        loader: 'postcss-loader',
                        options: {
                            postcssOptions: {
                                plugins: [
                                    require('@tailwindcss/postcss'),
                                ],
                            },
                        },
                    },
                ],
            },
            {
                test: /\.marko$/,
                use: [
                    {
                        loader: '@marko/webpack/loader',
                        options: {
                            babelConfig: {
                                presets: [
                                    ['@babel/preset-env', { targets: 'defaults' }]
                                ]
                            }
                        }
                    }
                ]
            }
        ],
    },
    plugins: [
        ...defaultConfig.plugins,
        new MiniCssExtractPlugin({
            filename: '[name].css',
        }),
        new CopyWebpackPlugin({
            patterns: [
                {
                    from: 'resources/assets/js/gutenberg/blocks/**/block.json',
                    to: ({ absoluteFilename }) => {
                        const relativePath = path.relative(
                            path.resolve(__dirname, 'resources/assets/js/gutenberg/blocks'),
                            absoluteFilename
                        );
                        return `${relativePath}`;
                    },
                },
                {
                    from: 'resources/assets/js/gutenberg/blocks/**/*.php',
                    to: ({ absoluteFilename }) => {
                        const relativePath = path.relative(
                            path.resolve(__dirname, 'resources/assets/js/gutenberg/blocks'),
                            absoluteFilename
                        );
                        return `${relativePath}`;
                    },
                },
            ],
        })
    ],
};
