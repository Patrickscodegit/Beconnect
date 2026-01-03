<?php

/**
 * Port Allowlist Configuration
 * 
 * Curated allowlist of UN/LOCODEs, countries, and IATA codes for Belgaco lanes.
 * This keeps the database clean and focused on relevant routes while remaining standards-based.
 * 
 * Belgaco lanes:
 * - Europe: Belgium, Netherlands, Germany, France, Spain, UK, Italy, Portugal
 * - GCC: UAE, Saudi Arabia, Qatar, Kuwait, Oman, Bahrain
 * - West Africa: Côte d'Ivoire, Ghana, Nigeria, Senegal, Gambia, Guinea, Sierra Leone, 
 *   Liberia, Togo, Benin, Cameroon, Gabon, Angola
 */

return [
    /**
     * Exact UN/LOCODEs to import (if using unlocodes mode)
     * Format: 'COUNTRYCODE' => ['LOC1', 'LOC2', ...]
     * Or flat: ['BEANR', 'NLRTM', ...]
     */
    'unlocodes' => [
        // Belgium
        'BEANR', // Antwerp
        'BEZEE', // Zeebrugge
        'BEBRU', // Brussels (airport)
        'BELGG', // Liège (airport)
        
        // Netherlands
        'NLRTM', // Rotterdam
        'NLAMS', // Amsterdam
        'NLVLI', // Vlissingen (Flushing)
        
        // Germany
        'DEHAM', // Hamburg
        'DEBRE', // Bremen
        'DEFRA', // Frankfurt (airport)
        
        // France
        'FRLEH', // Le Havre
        'FRMRS', // Marseille
        'FRCDG', // Paris CDG (airport)
        
        // Spain
        'ESBCN', // Barcelona
        'ESVLC', // Valencia
        'ESMAD', // Madrid (airport)
        
        // UK
        'GBLON', // London
        'GBFEL', // Felixstowe
        'GBLHR', // London Heathrow (airport)
        
        // Italy
        'ITGOA', // Genoa
        'ITNAP', // Naples
        'ITFCO', // Rome Fiumicino (airport)
        
        // Portugal
        'PTLIS', // Lisbon
        'PTOPO', // Porto
        
        // GCC - UAE
        'AEJEA', // Jebel Ali
        'AEDXB', // Dubai (airport)
        'AEDWC', // Dubai World Central (airport)
        
        // GCC - Saudi Arabia
        'SAJED', // Jeddah
        'SADMM', // Dammam
        'SARUH', // Riyadh (airport)
        
        // GCC - Qatar
        'QADOH', // Doha
        'QADOH', // Doha (airport)
        
        // GCC - Kuwait
        'KWKWI', // Kuwait
        
        // GCC - Oman
        'OMMCT', // Muscat
        
        // GCC - Bahrain
        'BHBHR', // Bahrain
        
        // West Africa - Côte d'Ivoire
        'CIABJ', // Abidjan
        
        // West Africa - Ghana
        'GHTEM', // Tema
        'GHACC', // Accra (airport)
        
        // West Africa - Nigeria
        'NGLOS', // Lagos
        'NGABV', // Abuja (airport)
        
        // West Africa - Senegal
        'SNDKR', // Dakar
        
        // West Africa - Gambia
        'GMBJL', // Banjul
        
        // West Africa - Guinea
        'GNCKY', // Conakry
        
        // West Africa - Sierra Leone
        'SLFNA', // Freetown
        
        // West Africa - Liberia
        'LRROB', // Monrovia (Robertsport)
        
        // West Africa - Togo
        'TGLFW', // Lomé
        
        // West Africa - Benin
        'BJCOO', // Cotonou
        
        // West Africa - Cameroon
        'CMDLA', // Douala
        
        // West Africa - Gabon
        'GALBV', // Libreville
        
        // West Africa - Angola
        'AOLAD', // Luanda
    ],

    /**
     * Country codes (ISO 3166-1 alpha-2) for Belgaco lanes
     */
    'countries' => [
        // Europe
        'BE', // Belgium
        'NL', // Netherlands
        'DE', // Germany
        'FR', // France
        'ES', // Spain
        'GB', // United Kingdom
        'IT', // Italy
        'PT', // Portugal
        
        // GCC (Gulf Cooperation Council)
        'AE', // United Arab Emirates
        'SA', // Saudi Arabia
        'QA', // Qatar
        'KW', // Kuwait
        'OM', // Oman
        'BH', // Bahrain
        
        // West Africa
        'CI', // Côte d'Ivoire
        'GH', // Ghana
        'NG', // Nigeria
        'SN', // Senegal
        'GM', // Gambia
        'GN', // Guinea
        'SL', // Sierra Leone
        'LR', // Liberia
        'TG', // Togo
        'BJ', // Benin
        'CM', // Cameroon
        'GA', // Gabon
        'AO', // Angola
    ],

    /**
     * Default filter mode: 'countries' or 'unlocodes'
     * When --allowlist=default, this mode is used
     */
    'default_mode' => 'countries',

    /**
     * Key airport IATA codes for Belgaco routes
     * Used by airport enrichment command when --allowlist=default
     */
    'iata' => [
        // Europe
        'BRU', // Brussels, Belgium
        'LGG', // Liège, Belgium
        'AMS', // Amsterdam, Netherlands
        'FRA', // Frankfurt, Germany
        'CDG', // Paris Charles de Gaulle, France
        'LHR', // London Heathrow, UK
        'MAD', // Madrid, Spain
        'FCO', // Rome Fiumicino, Italy
        'LIS', // Lisbon, Portugal
        
        // GCC
        'DXB', // Dubai, UAE
        'DWC', // Dubai World Central, UAE
        'JED', // Jeddah, Saudi Arabia
        'RUH', // Riyadh, Saudi Arabia
        'DOH', // Doha, Qatar
        'KWI', // Kuwait
        'MCT', // Muscat, Oman
        'BAH', // Bahrain
        
        // West Africa
        'ABJ', // Abidjan, Côte d'Ivoire
        'ACC', // Accra, Ghana
        'LOS', // Lagos, Nigeria
        'ABV', // Abuja, Nigeria
        'DKR', // Dakar, Senegal
        'BJL', // Banjul, Gambia
        'CKY', // Conakry, Guinea
        'FNA', // Freetown, Sierra Leone
        'ROB', // Monrovia, Liberia
        'LFW', // Lomé, Togo
        'COO', // Cotonou, Benin
        'DLA', // Douala, Cameroon
        'LBV', // Libreville, Gabon
        'LAD', // Luanda, Angola
        
        // Major hubs (optional)
        'JFK', // New York, USA
        'MIA', // Miami, USA
    ],

    /**
     * Notes about the allowlist
     */
    'notes' => [
        'Belgaco primary lanes: Europe ↔ West Africa, Europe ↔ GCC',
        'This allowlist focuses on major ports and airports on these routes',
        'Can be extended as new routes are added',
        'Use --allowlist=all to import everything (not recommended for production)',
    ],
];

