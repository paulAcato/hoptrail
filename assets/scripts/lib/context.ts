import { createContext, useContext } from '@wordpress/element';

/** Whether rules come from more than one redirect plugin; plugin names only add information then. */
export const MultiSourceContext = createContext(false);

export const useMultiSource = (): boolean => useContext(MultiSourceContext);
