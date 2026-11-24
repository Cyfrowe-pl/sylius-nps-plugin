@nps
Feature: Answering an NPS survey
    In order to learn what customers make of their orders
    As a Store Owner
    I want a customer to be able to score an order from the email and say why

    Background:
        Given a survey has been sent to a customer

    Scenario: Scoring an order straight from the email
        When the customer picks 9 in the email
        Then the survey holds a score of 9

    Scenario: Saying why, after scoring
        When the customer picks 3 in the email
        And the customer leaves the comment "The parcel arrived open"
        Then the survey holds a score of 3
        And the survey is finished and holds the comment "The parcel arrived open"

    Scenario: A number that is not on the scale is refused
        When the customer picks 42 in the email
        Then the survey holds no score at all
