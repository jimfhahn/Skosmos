import { getConfigurationForCLITests } from '../../support/accessibility.js'

describe('This is used to avoid unnecessary redundancy of code', () => {
    before(() => {
        cy.visit('/')
        cy.injectAxe()
    })

    // Configuration for the accessibility test
    getConfigurationForCLITests()
})

