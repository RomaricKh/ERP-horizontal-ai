// assets/js/components/appShell.config.js
export const MENU_CONFIG = [
    {
        id: 'goToDashboard',
        label: 'Dashboard',
        icon: 'fas fa-fw fa-tachometer-alt',
        hash: '#/dashboard',
        permission: 'dashboard.view'
    },
    {
        id: 'goToCharts',
        label: 'Charts',
        icon: 'fas fa-fw fa-chart-area',
        hash: '#/charts',
        permission: 'charts.view'
    },
    {
        id: 'goToTables',
        label: 'Tables',
        icon: 'fas fa-fw fa-table',
        hash: '#/tables',
        permission: 'tables.view'
    },
    {
        id: 'goToInventory',
        label: 'Inventory',
        icon: 'fas fa-fw fa-warehouse',
        hash: '#/inventory',
        permission: 'inventory.view'
    },
    {
        id: 'goToBusinesses',
        label: 'Businesses',
        icon: 'fas fa-fw fa-briefcase',
        hash: '#/businesses',
        permission: 'businesses.view'
    },
    {
        id: 'goToUsers',
        label: 'Users',
        icon: 'fas fa-fw fa-users',
        hash: '#/users',
        permission: 'users.view'
    }
];

export const USER_INFO_CONFIG =[
	{
		label: 'Profile',
		icon: 'fas fa-user fa-sm fa-fw mr-2 text-gray-400',
		hash: '#/profile',
		permission: 'profile.view'
	},
	{
		label: 'Settings',
		icon: 'fas fa-cogs fa-sm fa-fw mr-2 text-gray-400',
		hash: '#/settings',
		permission: 'settings.view'
	},
	{
		label: 'Activity Log',
		icon: 'fas fa-list fa-sm fa-fw mr-2 text-gray-400',
		hash: '#/activityLog',
		permission: 'activityLog.view'
	}
];