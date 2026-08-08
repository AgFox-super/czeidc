# Cangzhou Cloud - Virtual Hosting Retail System

GitHub Project URL: https://github.com/AgFox-super/czeidc

Demo Site: https://demo.mcedm.top/admin (Username: admin, Password: 123456)

An online virtual hosting ordering system based on PHP 7.2 + MySQL. Features include user registration via email, online plan selection, order placement via YiPay (Alipay/WeChat), automatic hosting provisioning via MagicCube Finance (Mofang) or EP Panel APIs after payment, a dedicated admin portal, and a built-in simple ticketing system. The frontend utilizes AJAX throughout for a seamless, page-reload-free experience.

## Usage

Upload the compressed archive to your server's web directory and extract it; the system supports running on shared hosting.

## Installation

Upload the files and visit `http://your-domain`; it will automatically redirect to the installation page. Follow the prompts to enter the required information, and be sure to secure your admin URL.

## Operation

Access `http://your-domain/<your-admin-path>` and log in using the administrator credentials. After logging in, configure the payment settings, SMTP, and the upstream API URL for MagicCube Finance. Note: Using the Baota (BT) Panel API for integration is strongly discouraged due to potential security risks. It is highly recommended to integrate with a self-hosted EP Panel; integration with MagicCube Finance is the second-best option (though it has a minor bug).

### Disclaimer: This system is completely free; secondary development and redistribution are permitted. If you obtained this system by purchasing it, congratulations—you have been scammed.

### If you have any questions or feedback, please email: admin@mcedm.top. Thank you for using this system!
