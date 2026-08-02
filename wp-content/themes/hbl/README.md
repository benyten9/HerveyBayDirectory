# HBL - Hervey Bay Local Directory Theme

A modern, Bootstrap-powered WordPress theme fully compatible with Elementor for the Hervey Bay Directory website.

## Description

HBL is a custom WordPress theme designed specifically for the Hervey Bay local business directory. Built with Bootstrap 5.3 and optimized for Elementor page builder, this theme provides a robust foundation for creating a comprehensive local business directory website.

## Features

- **Bootstrap 5.3 Integration**: Modern, responsive design with Bootstrap framework
- **Elementor Compatible**: Full support for Elementor page builder
- **Custom Widgets**: 8 custom Elementor widgets designed for directory functionality:
  - Directory Search
  - Business Cards
  - Featured Businesses
  - Categories Grid
  - Testimonials
  - FAQ
  - Local News
  - Events Calendar
- **Responsive Design**: Mobile-first approach ensuring great experience on all devices
- **SEO Optimized**: Clean, semantic HTML5 markup
- **Accessibility Ready**: WCAG 2.1 compliant
- **Custom Post Types**: Support for business listings and directory entries
- **Advanced Typography**: Flexible font sizing and line height system
- **Color Variables**: Easy customization through CSS variables
- **Cross-browser Compatible**: Works seamlessly across modern browsers

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Elementor plugin (recommended)

## Installation

1. Download the theme files
2. Upload the `hbl` folder to `/wp-content/themes/` directory
3. Activate the theme through the 'Appearance > Themes' menu in WordPress
4. Install and activate Elementor plugin for full functionality

## Theme Structure

```
hbl/
├── assets/
│   └── js/
│       └── theme.js
├── inc/
│   ├── widgets/
│   │   ├── class-directory-search.php
│   │   ├── class-business-cards.php
│   │   ├── class-featured-businesses.php
│   │   ├── class-categories-grid.php
│   │   ├── class-testimonials.php
│   │   ├── class-faq.php
│   │   ├── class-local-news.php
│   │   └── class-events-calendar.php
│   ├── bootstrap-navwalker.php
│   ├── customizer.php
│   └── template-tags.php
├── template-parts/
│   └── content.php
├── footer.php
├── functions.php
├── header.php
├── index.php
├── page.php
├── sidebar.php
├── single.php
├── style.css
└── README.md
```

## Customization

### CSS Variables

The theme uses CSS custom properties (variables) for easy customization. Main variables are defined in `style.css`:

- Colors: `--hbl-primary`, `--hbl-secondary`, `--hbl-accent`
- Typography: `--hbl-font-primary`, `--hbl-font-size-*`
- Spacing: `--hbl-spacing-*`
- Layout: `--hbl-container-max-width`

### Widget Customization

All custom widgets are located in the `inc/widgets/` directory and can be modified to suit your needs.

## Development

This theme was developed following WordPress coding standards and best practices.

### Browser Support

- Chrome (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)
- Edge (latest 2 versions)

## Changelog

### Version 1.0.0
- Initial release
- Bootstrap 5.3 integration
- 8 custom Elementor widgets
- Responsive design
- Accessibility features

## Credits

- Bootstrap: https://getbootstrap.com/
- Bootstrap Icons: https://icons.getbootstrap.com/
- Elementor: https://elementor.com/

## License

This theme is licensed under the GPL v2 or later.

## Support

For support and documentation, please visit the theme support page or contact the development team.

## Author

HBL Team

---

© 2025 Hervey Bay Local Directory. All rights reserved.

